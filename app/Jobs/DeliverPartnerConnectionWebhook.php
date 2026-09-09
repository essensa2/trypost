<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConnectionAuthorizationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\InteractsWithQueue;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DeliverPartnerConnectionWebhook implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public int $uniqueFor = 86400;

    public function __construct(public string $authorizationRequestId) {}

    public function uniqueId(): string
    {
        return $this->authorizationRequestId;
    }

    public function handle(): void
    {
        $authorizationRequest = ConnectionAuthorizationRequest::with(['partnerWorkspace', 'socialAccount'])
            ->findOrFail($this->authorizationRequestId);

        if ($authorizationRequest->webhook_delivered_at) {
            return;
        }

        $url = (string) config('services.ais.webhook_url');
        $secret = (string) config('services.ais.webhook_secret');

        if ($url === '' || $secret === '') {
            throw new RuntimeException('AIS webhook URL and secret must be configured.');
        }

        $eventId = (string) $authorizationRequest->webhook_event_id;
        $timestamp = (string) now()->timestamp;
        $socialAccount = $authorizationRequest->socialAccount;

        if (! $socialAccount) {
            throw new RuntimeException('Connected social account is missing.');
        }

        $payload = [
            'event_id' => $eventId,
            'type' => 'social_account.connected',
            'occurred_at' => $authorizationRequest->completed_at?->toIso8601String(),
            'data' => [
                'external_project_id' => $authorizationRequest->partnerWorkspace->external_project_id,
                'workspace_id' => $authorizationRequest->workspace_id,
                'connection_request_id' => $authorizationRequest->id,
                'external_request_id' => $authorizationRequest->external_request_id,
                'platform' => $authorizationRequest->platform->value,
                'social_account' => [
                    'id' => $socialAccount->id,
                    'platform' => $socialAccount->platform->value,
                    'platform_user_id' => $socialAccount->platform_user_id,
                    'username' => $socialAccount->username,
                    'display_name' => $socialAccount->display_name,
                    'status' => $socialAccount->status->value,
                    'is_active' => (bool) $socialAccount->is_active,
                ],
            ],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        $authorizationRequest->increment('webhook_attempts');

        Http::withHeaders([
            'X-TryPost-Event-Id' => $eventId,
            'X-TryPost-Timestamp' => $timestamp,
            'X-TryPost-Signature' => "v1={$signature}",
        ])
            ->withBody($body, 'application/json')
            ->connectTimeout(5)
            ->timeout(15)
            ->post($url)
            ->throw();

        $authorizationRequest->forceFill(['webhook_delivered_at' => now()])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $authorizationRequest = ConnectionAuthorizationRequest::find($this->authorizationRequestId);

        if (! $authorizationRequest) {
            return;
        }

        $failure = [
            'event' => 'webhook_failed',
            'at' => now()->toIso8601String(),
            'message' => 'Partner connection webhook delivery failed.',
            'exception_class' => $exception ? $exception::class : null,
        ];

        if ($exception instanceof RequestException) {
            $failure['http_status'] = $exception->response->status();
        }

        $authorizationRequest->update([
            'audit_log' => [...($authorizationRequest->audit_log ?? []), $failure],
        ]);
    }
}

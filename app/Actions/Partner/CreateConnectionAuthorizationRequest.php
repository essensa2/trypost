<?php

declare(strict_types=1);

namespace App\Actions\Partner;

use App\Enums\Partner\ConnectionAuthorizationStatus;
use App\Models\ConnectionAuthorizationRequest;
use App\Models\PartnerWorkspace;
use Illuminate\Support\Str;

class CreateConnectionAuthorizationRequest
{
    /**
     * @param  array{external_request_id: string, platform: string}  $data
     * @return array{authorization_request: ConnectionAuthorizationRequest, created: bool, token: string|null}
     */
    public function execute(PartnerWorkspace $partnerWorkspace, array $data): array
    {
        $existing = $partnerWorkspace->connectionAuthorizationRequests()
            ->where('external_request_id', $data['external_request_id'])
            ->first();

        if ($existing) {
            $this->expireIfNeeded($existing);

            return [
                'authorization_request' => $existing->load('socialAccount'),
                'created' => false,
                'token' => null,
            ];
        }

        $token = Str::random(80);
        $authorizationRequest = $partnerWorkspace->connectionAuthorizationRequests()->create([
            'workspace_id' => $partnerWorkspace->workspace_id,
            'external_request_id' => $data['external_request_id'],
            'platform' => $data['platform'],
            'token_digest' => hash('sha256', $token),
            'status' => ConnectionAuthorizationStatus::Pending,
            'expires_at' => now()->addDays((int) config('services.ais.connection_link_ttl_days', 7)),
            'audit_log' => [[
                'event' => 'created',
                'at' => now()->toIso8601String(),
            ]],
        ]);

        return [
            'authorization_request' => $authorizationRequest,
            'created' => true,
            'token' => $token,
        ];
    }

    public function expireIfNeeded(ConnectionAuthorizationRequest $authorizationRequest): void
    {
        if (
            in_array($authorizationRequest->status, [ConnectionAuthorizationStatus::Pending, ConnectionAuthorizationStatus::Claimed], true)
            && $authorizationRequest->expires_at->isPast()
        ) {
            $authorizationRequest->update([
                'status' => ConnectionAuthorizationStatus::Expired,
                'audit_log' => [...($authorizationRequest->audit_log ?? []), [
                    'event' => 'expired',
                    'at' => now()->toIso8601String(),
                ]],
            ]);
        }
    }
}

<?php

declare(strict_types=1);

use App\Enums\Partner\ConnectionAuthorizationStatus;
use App\Enums\Partner\ConnectionPlatform;
use App\Jobs\DeliverPartnerConnectionWebhook;
use App\Models\ConnectionAuthorizationRequest;
use App\Models\PartnerWorkspace;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('connection webhook is signed and never exposes provider tokens', function () {
    config()->set('services.ais.webhook_url', 'https://ais.test/api/integrations/trypost/connection-events');
    config()->set('services.ais.webhook_secret', 'webhook-secret');

    Http::fake(['https://ais.test/*' => Http::response(null, 204)]);

    $workspace = Workspace::factory()->create();
    $partnerWorkspace = PartnerWorkspace::factory()->create([
        'workspace_id' => $workspace->id,
        'external_project_id' => '22222222-2222-4222-8222-222222222222',
    ]);
    $socialAccount = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'access_token' => 'secret-provider-token',
        'refresh_token' => 'secret-provider-refresh-token',
    ]);
    $eventId = (string) Str::uuid();
    $authorizationRequest = ConnectionAuthorizationRequest::factory()->create([
        'partner_workspace_id' => $partnerWorkspace->id,
        'workspace_id' => $workspace->id,
        'platform' => ConnectionPlatform::Instagram,
        'status' => ConnectionAuthorizationStatus::Completed,
        'social_account_id' => $socialAccount->id,
        'completed_at' => now(),
        'webhook_event_id' => $eventId,
    ]);

    (new DeliverPartnerConnectionWebhook($authorizationRequest->id))->handle();

    Http::assertSent(function (Request $request) use ($eventId): bool {
        $timestamp = $request->header('X-TryPost-Timestamp')[0];
        $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'webhook-secret');

        return $request->url() === 'https://ais.test/api/integrations/trypost/connection-events'
            && $request->header('X-TryPost-Event-Id')[0] === $eventId
            && hash_equals($expected, $request->header('X-TryPost-Signature')[0])
            && $request->data()['data']['social_account']['is_active'] === true
            && ! str_contains($request->body(), 'secret-provider-token')
            && ! str_contains($request->body(), 'secret-provider-refresh-token');
    });

    expect($authorizationRequest->fresh()->webhook_delivered_at)->not->toBeNull();
});

test('webhook failure audit never stores an upstream exception message or body', function () {
    $workspace = Workspace::factory()->create();
    $partnerWorkspace = PartnerWorkspace::factory()->create(['workspace_id' => $workspace->id]);
    $authorizationRequest = ConnectionAuthorizationRequest::factory()->create([
        'partner_workspace_id' => $partnerWorkspace->id,
        'workspace_id' => $workspace->id,
    ]);

    (new DeliverPartnerConnectionWebhook($authorizationRequest->id))
        ->failed(new RuntimeException('Request failed: access_token=top-secret'));

    $failure = $authorizationRequest->fresh()->audit_log[0];
    $audit = json_encode($failure, JSON_THROW_ON_ERROR);

    expect($failure)
        ->toMatchArray([
            'event' => 'webhook_failed',
            'message' => 'Partner connection webhook delivery failed.',
            'exception_class' => RuntimeException::class,
        ])
        ->not->toHaveKey('http_status');
    expect($audit)
        ->not->toContain('Request failed')
        ->not->toContain('access_token')
        ->not->toContain('top-secret');
});

test('webhook failure audit records an HTTP status without storing the response body', function () {
    config()->set('services.ais.webhook_url', 'https://ais.test/api/integrations/trypost/connection-events');
    config()->set('services.ais.webhook_secret', 'webhook-secret');

    Http::fake(['https://ais.test/*' => Http::response('upstream access_token=top-secret', 503)]);

    $workspace = Workspace::factory()->create();
    $partnerWorkspace = PartnerWorkspace::factory()->create(['workspace_id' => $workspace->id]);
    $socialAccount = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $authorizationRequest = ConnectionAuthorizationRequest::factory()->create([
        'partner_workspace_id' => $partnerWorkspace->id,
        'workspace_id' => $workspace->id,
        'platform' => ConnectionPlatform::Instagram,
        'status' => ConnectionAuthorizationStatus::Completed,
        'social_account_id' => $socialAccount->id,
        'completed_at' => now(),
        'webhook_event_id' => (string) Str::uuid(),
    ]);
    $job = new DeliverPartnerConnectionWebhook($authorizationRequest->id);

    try {
        $job->handle();
    } catch (RequestException $exception) {
        $job->failed($exception);
    }

    $failure = $authorizationRequest->fresh()->audit_log[0];
    $audit = json_encode($failure, JSON_THROW_ON_ERROR);

    expect($failure)->toMatchArray([
        'event' => 'webhook_failed',
        'message' => 'Partner connection webhook delivery failed.',
        'exception_class' => RequestException::class,
        'http_status' => 503,
    ]);
    expect($audit)
        ->not->toContain('upstream')
        ->not->toContain('access_token')
        ->not->toContain('top-secret');
});

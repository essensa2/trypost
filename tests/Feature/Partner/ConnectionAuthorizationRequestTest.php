<?php

declare(strict_types=1);

use App\Enums\Partner\ConnectionAuthorizationStatus;
use App\Enums\Partner\ConnectionPlatform;
use App\Models\ConnectionAuthorizationRequest;
use App\Models\PartnerWorkspace;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('services.ais.partner_token', 'partner-test-token');
    config()->set('services.ais.connection_link_ttl_days', 7);

    $this->workspace = Workspace::factory()->create();
    $this->partnerWorkspace = PartnerWorkspace::factory()->create([
        'workspace_id' => $this->workspace->id,
        'external_project_id' => '22222222-2222-4222-8222-222222222222',
        'project_name' => 'Heatline',
    ]);
});

test('connection request returns the raw link once and stores only its digest', function () {
    $payload = [
        'workspace_id' => $this->workspace->id,
        'external_request_id' => '44444444-4444-4444-8444-444444444444',
        'platform' => 'facebook',
    ];

    $first = $this->withToken('partner-test-token')
        ->postJson(route('api.partner.connection-requests.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('status', 'pending');

    $authorizationUrl = $first->json('authorization_url');
    $rawToken = Str::after($authorizationUrl, '#token=');
    $stored = ConnectionAuthorizationRequest::where('external_request_id', '44444444-4444-4444-8444-444444444444')->firstOrFail();

    expect($rawToken)->toHaveLength(80);
    expect(parse_url($authorizationUrl, PHP_URL_PATH))->toBe(parse_url(route('guest.connections.entry'), PHP_URL_PATH));
    expect(parse_url($authorizationUrl, PHP_URL_QUERY))->toBeNull();
    expect($stored->token_digest)->toBe(hash('sha256', $rawToken))->not->toBe($rawToken);

    $this->withToken('partner-test-token')
        ->postJson(route('api.partner.connection-requests.store'), $payload)
        ->assertOk()
        ->assertJsonPath('authorization_url', null);
});

test('claim exchanges the opaque URL token for a session and redirects to a clean URL', function () {
    $token = Str::random(80);
    $authorizationRequest = ConnectionAuthorizationRequest::factory()->create([
        'partner_workspace_id' => $this->partnerWorkspace->id,
        'workspace_id' => $this->workspace->id,
        'token_digest' => hash('sha256', $token),
    ]);

    $this->get(route('guest.connections.entry'))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('guest/ConnectionExchange'));

    $response = $this->post(route('guest.connections.claim'), ['token' => $token]);

    $response->assertRedirect(route('guest.connections.show', $authorizationRequest));
    $response->assertHeader('Cache-Control', 'no-store, private');
    expect(session('guest_social_connection.request_id'))->toBe($authorizationRequest->id);
    expect($authorizationRequest->fresh()->status)->toBe(ConnectionAuthorizationStatus::Claimed);

    $this->get(route('guest.connections.show', $authorizationRequest))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('guest/ConnectionPortal')
            ->where('projectName', 'Heatline')
            ->where('platform', 'facebook'));
});

test('a claimed link cannot be opened from a second browser session', function () {
    $token = Str::random(80);
    ConnectionAuthorizationRequest::factory()->create([
        'partner_workspace_id' => $this->partnerWorkspace->id,
        'workspace_id' => $this->workspace->id,
        'token_digest' => hash('sha256', $token),
    ]);

    $this->post(route('guest.connections.claim'), ['token' => $token])->assertRedirect();
    $this->app['session']->flush();

    $this->post(route('guest.connections.claim'), ['token' => $token])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('guest/ConnectionResult')
            ->where('success', false));
});

test('connection request cannot target a workspace outside the AIS partner mapping', function () {
    $otherWorkspace = Workspace::factory()->create();

    $this->withToken('partner-test-token')->postJson(route('api.partner.connection-requests.store'), [
        'workspace_id' => $otherWorkspace->id,
        'external_request_id' => '55555555-5555-4555-8555-555555555555',
        'platform' => 'facebook',
    ])->assertNotFound();
});

test('database rejects a partner workspace and workspace mismatch', function () {
    $otherWorkspace = Workspace::factory()->create();

    expect(fn () => ConnectionAuthorizationRequest::create([
        'partner_workspace_id' => $this->partnerWorkspace->id,
        'workspace_id' => $otherWorkspace->id,
        'external_request_id' => '66666666-6666-4666-8666-666666666666',
        'platform' => ConnectionPlatform::Facebook,
        'token_digest' => hash('sha256', Str::random(80)),
        'status' => ConnectionAuthorizationStatus::Pending,
        'expires_at' => now()->addDay(),
    ]))->toThrow(QueryException::class);
});

test('an expired connection link cannot be claimed', function () {
    $token = Str::random(80);
    $authorizationRequest = ConnectionAuthorizationRequest::factory()->create([
        'partner_workspace_id' => $this->partnerWorkspace->id,
        'workspace_id' => $this->workspace->id,
        'token_digest' => hash('sha256', $token),
        'expires_at' => now()->subMinute(),
    ]);

    $this->post(route('guest.connections.claim'), ['token' => $token])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('guest/ConnectionResult')
            ->where('success', false));

    expect($authorizationRequest->fresh()->status)->toBe(ConnectionAuthorizationStatus::Expired);
});

test('revoking a claimed request invalidates its clean portal URL', function () {
    $claimSecret = Str::random(80);
    $authorizationRequest = ConnectionAuthorizationRequest::factory()->create([
        'partner_workspace_id' => $this->partnerWorkspace->id,
        'workspace_id' => $this->workspace->id,
        'platform' => ConnectionPlatform::Instagram,
        'status' => ConnectionAuthorizationStatus::Claimed,
        'claim_digest' => hash('sha256', $claimSecret),
    ]);

    $this->withToken('partner-test-token')->deleteJson(
        route('api.partner.connection-requests.destroy', $authorizationRequest->external_request_id),
    )->assertNoContent();

    $this->withSession(['guest_social_connection' => [
        'request_id' => $authorizationRequest->id,
        'claim_secret' => $claimSecret,
    ]])->get(route('guest.connections.show', $authorizationRequest))->assertGone();
});

<?php

declare(strict_types=1);

use App\Models\AccessToken;
use App\Models\PartnerWorkspace;
use App\Models\SocialAccount;
use App\Models\User;

beforeEach(function () {
    config()->set('trypost.self_hosted', true);
    config()->set('services.ais.partner_token', 'partner-test-token');

    $this->serviceUser = User::factory()->create();
    config()->set('services.ais.service_user_id', $this->serviceUser->id);
});

test('partner workspace provisioning requires the configured bearer token', function () {
    $this->postJson(route('api.partner.workspaces.store'), [
        'external_project_id' => '11111111-1111-4111-8111-111111111111',
        'name' => 'Heatline',
    ])->assertUnauthorized();
});

test('partner workspace provisioning is idempotent and returns its credential only once', function () {
    $payload = [
        'external_project_id' => '22222222-2222-4222-8222-222222222222',
        'name' => 'Heatline',
        'locale' => 'en',
    ];

    $first = $this->withToken('partner-test-token')
        ->postJson(route('api.partner.workspaces.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonStructure(['workspace_id', 'external_project_id', 'name', 'api_key']);

    expect($first->json('api_key'))->toBeString()->not->toBeEmpty();

    $second = $this->withToken('partner-test-token')
        ->postJson(route('api.partner.workspaces.store'), $payload)
        ->assertOk()
        ->assertJsonPath('created', false)
        ->assertJsonPath('api_key', null);

    expect($second->json('workspace_id'))->toBe($first->json('workspace_id'));
    expect($this->serviceUser->load('account')->account->workspaces()->count())->toBe(1);
});

test('partner can explicitly rotate a workspace credential', function () {
    $created = $this->withToken('partner-test-token')->postJson(route('api.partner.workspaces.store'), [
        'external_project_id' => '33333333-3333-4333-8333-333333333333',
        'name' => 'Rotate Me',
    ])->assertCreated();

    $oldTokenId = AccessToken::query()
        ->where('workspace_id', $created->json('workspace_id'))
        ->value('id');

    $rotated = $this->withToken('partner-test-token')->postJson(
        route('api.partner.workspaces.credentials.rotate', $created->json('workspace_id')),
    )->assertOk();

    expect($rotated->json('api_key'))->toBeString()->not->toBe($created->json('api_key'));
    expect(AccessToken::findOrFail($oldTokenId)->revoked)->toBeTrue();
});

test('partner social account reconciliation exposes normalized active state without tokens', function () {
    $partnerWorkspace = PartnerWorkspace::factory()->create();
    $socialAccount = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $partnerWorkspace->workspace_id,
        'is_active' => false,
        'access_token' => 'provider-secret',
    ]);

    $response = $this->withToken('partner-test-token')->getJson(
        route('api.partner.workspaces.social-accounts', $partnerWorkspace->workspace_id),
    )->assertOk()
        ->assertJsonPath('social_accounts.0.id', $socialAccount->id)
        ->assertJsonPath('social_accounts.0.is_active', false);

    expect($response->getContent())->not->toContain('provider-secret');
});

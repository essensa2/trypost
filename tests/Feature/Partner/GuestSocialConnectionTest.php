<?php

declare(strict_types=1);

use App\Enums\Partner\ConnectionAuthorizationStatus;
use App\Enums\Partner\ConnectionPlatform;
use App\Jobs\DeliverPartnerConnectionWebhook;
use App\Models\ConnectionAuthorizationRequest;
use App\Models\PartnerWorkspace;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

test('a guest Instagram callback connects the account and completes the grant', function () {
    Queue::fake();
    config()->set('trypost.self_hosted', true);

    $workspace = Workspace::factory()->create();
    $partnerWorkspace = PartnerWorkspace::factory()->create(['workspace_id' => $workspace->id]);
    $claimSecret = Str::random(80);
    $authorizationRequest = ConnectionAuthorizationRequest::factory()->create([
        'partner_workspace_id' => $partnerWorkspace->id,
        'workspace_id' => $workspace->id,
        'platform' => ConnectionPlatform::Instagram,
        'status' => ConnectionAuthorizationStatus::Claimed,
        'claim_digest' => hash('sha256', $claimSecret),
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('instagram-123');
    $socialiteUser->shouldReceive('getNickname')->andReturn('heatline');
    $socialiteUser->shouldReceive('getName')->andReturn('Heatline');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'provider-token';
    $socialiteUser->refreshToken = 'provider-refresh-token';
    $socialiteUser->expiresIn = 3600;
    $socialiteUser->user = ['account_type' => 'BUSINESS'];

    Socialite::shouldReceive('driver')
        ->with('instagram')
        ->andReturn(Mockery::mock(['user' => $socialiteUser]));

    $this->withSession([
        'social_connection_mode' => 'guest',
        'social_connect_workspace' => $workspace->id,
        'guest_social_connection' => [
            'request_id' => $authorizationRequest->id,
            'claim_secret' => $claimSecret,
        ],
    ])->get(route('app.social.instagram.callback'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('guest/ConnectionResult')
            ->where('success', true));

    $completed = $authorizationRequest->fresh()->load('socialAccount');
    expect($completed->status)->toBe(ConnectionAuthorizationStatus::Completed);
    expect($completed->socialAccount)->not->toBeNull();
    Queue::assertPushed(DeliverPartnerConnectionWebhook::class, fn ($job) => $job->authorizationRequestId === $completed->id);
});

test('guest account token writes roll back when the grant can no longer complete', function (string $raceOutcome) {
    Queue::fake();
    config()->set('trypost.self_hosted', true);

    $workspace = Workspace::factory()->create();
    $partnerWorkspace = PartnerWorkspace::factory()->create(['workspace_id' => $workspace->id]);
    $claimSecret = Str::random(80);
    $authorizationRequest = ConnectionAuthorizationRequest::factory()->create([
        'partner_workspace_id' => $partnerWorkspace->id,
        'workspace_id' => $workspace->id,
        'platform' => ConnectionPlatform::Instagram,
        'status' => ConnectionAuthorizationStatus::Claimed,
        'claim_digest' => hash('sha256', $claimSecret),
        'expires_at' => now()->addDay(),
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('instagram-race');
    $socialiteUser->shouldReceive('getNickname')->andReturn('race');
    $socialiteUser->shouldReceive('getName')->andReturn('Race');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'new-provider-token';
    $socialiteUser->refreshToken = 'new-provider-refresh-token';
    $socialiteUser->expiresIn = 3600;
    $socialiteUser->user = ['account_type' => 'BUSINESS'];

    Socialite::shouldReceive('driver')
        ->with('instagram')
        ->andReturn(Mockery::mock()->shouldReceive('user')->andReturnUsing(function () use ($authorizationRequest, $raceOutcome, $socialiteUser) {
            $authorizationRequest->update($raceOutcome === 'revoked'
                ? ['status' => ConnectionAuthorizationStatus::Revoked]
                : ['expires_at' => now()->subMinute()]);

            return $socialiteUser;
        })->getMock());

    $this->withSession([
        'social_connection_mode' => 'guest',
        'social_connect_workspace' => $workspace->id,
        'guest_social_connection' => [
            'request_id' => $authorizationRequest->id,
            'claim_secret' => $claimSecret,
        ],
    ])->get(route('app.social.instagram.callback'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('guest/ConnectionResult')
            ->where('success', false));

    expect($workspace->socialAccounts()->where('platform_user_id', 'instagram-race')->exists())->toBeFalse();
    expect($authorizationRequest->fresh()->social_account_id)->toBeNull();
    Queue::assertNotPushed(DeliverPartnerConnectionWebhook::class);
})->with([
    'revoked between OAuth return and callback commit' => ['revoked'],
    'expired between OAuth return and callback commit' => ['expired'],
]);

test('guest Facebook page selection is marked as private connection UI', function () {
    config()->set('trypost.self_hosted', true);

    $workspace = Workspace::factory()->create();
    $partnerWorkspace = PartnerWorkspace::factory()->create(['workspace_id' => $workspace->id]);
    $claimSecret = Str::random(80);
    $authorizationRequest = ConnectionAuthorizationRequest::factory()->create([
        'partner_workspace_id' => $partnerWorkspace->id,
        'workspace_id' => $workspace->id,
        'platform' => ConnectionPlatform::Facebook,
        'status' => ConnectionAuthorizationStatus::Claimed,
        'claim_digest' => hash('sha256', $claimSecret),
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook-user');
    $socialiteUser->token = 'facebook-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    Http::fake([
        'https://graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
            ['id' => 'page-1', 'name' => 'One', 'picture' => ['data' => ['url' => null]], 'access_token' => 'page-1-token'],
            ['id' => 'page-2', 'name' => 'Two', 'picture' => ['data' => ['url' => null]], 'access_token' => 'page-2-token'],
        ]]),
        'https://graph.facebook.com/*/me*' => Http::response(['id' => 'facebook-user', 'name' => 'User']),
    ]);

    $this->withSession([
        'social_connection_mode' => 'guest',
        'social_connect_workspace' => $workspace->id,
        'guest_social_connection' => [
            'request_id' => $authorizationRequest->id,
            'claim_secret' => $claimSecret,
        ],
    ])->get(route('app.social.facebook.callback'))
        ->assertRedirect(route('app.social.facebook.select-page'));

    $this->get(route('app.social.facebook.select-page'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounts/FacebookPageSelect')
            ->where('guestConnection', true));
});

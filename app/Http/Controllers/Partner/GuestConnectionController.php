<?php

declare(strict_types=1);

namespace App\Http\Controllers\Partner;

use App\Actions\Partner\ClaimConnectionAuthorizationRequest;
use App\Http\Controllers\Controller;
use App\Models\ConnectionAuthorizationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuestConnectionController extends Controller
{
    public function entry(): Response
    {
        Inertia::setRootView('guest');

        return Inertia::render('guest/ConnectionExchange', [
            'claimUrl' => route('guest.connections.claim'),
            'copy' => [
                'title' => __('guest_connections.exchange_title'),
                'description' => __('guest_connections.exchange_description'),
                'invalid' => __('guest_connections.invalid_or_expired'),
            ],
        ]);
    }

    public function claim(
        Request $request,
        ClaimConnectionAuthorizationRequest $claimConnectionAuthorizationRequest,
    ): RedirectResponse|Response {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:80', 'regex:/^[A-Za-z0-9]+$/'],
        ]);
        $existingClaim = $request->session()->get('guest_social_connection.claim_secret');
        $claim = $claimConnectionAuthorizationRequest->execute(
            $validated['token'],
            is_string($existingClaim) ? $existingClaim : null,
        );

        if (! $claim) {
            return $this->result(false, __('guest_connections.invalid_or_expired'));
        }

        $authorizationRequest = $claim['authorization_request'];
        $request->session()->put('guest_social_connection', [
            'request_id' => $authorizationRequest->id,
            'claim_secret' => $claim['claim_secret'],
        ]);

        return redirect()->route('guest.connections.show', $authorizationRequest);
    }

    public function show(ConnectionAuthorizationRequest $connectionAuthorizationRequest): Response
    {
        Inertia::setRootView('guest');
        $connectionAuthorizationRequest->loadMissing('partnerWorkspace');

        $platform = $connectionAuthorizationRequest->platform;

        return Inertia::render('guest/ConnectionPortal', [
            'projectName' => $connectionAuthorizationRequest->partnerWorkspace->project_name,
            'platform' => $platform->value,
            'platformLabel' => $platform->socialPlatform()->label(),
            'permissions' => $platform->value === 'facebook'
                ? [
                    __('guest_connections.permissions.facebook.publish'),
                    __('guest_connections.permissions.facebook.analytics'),
                ]
                : [
                    __('guest_connections.permissions.instagram.publish'),
                    __('guest_connections.permissions.instagram.analytics'),
                ],
            'connectUrl' => $platform->value === 'facebook'
                ? route('guest.connections.facebook.connect', $connectionAuthorizationRequest)
                : route('guest.connections.instagram.connect', $connectionAuthorizationRequest),
            'copy' => [
                'eyebrow' => __('guest_connections.eyebrow'),
                'title' => __('guest_connections.title', ['platform' => $platform->socialPlatform()->label()]),
                'description' => __('guest_connections.description', [
                    'project' => $connectionAuthorizationRequest->partnerWorkspace->project_name,
                    'platform' => $platform->socialPlatform()->label(),
                ]),
                'permissionsTitle' => __('guest_connections.permissions_title'),
                'privacy' => __('guest_connections.privacy'),
                'connect' => __('guest_connections.connect', ['platform' => $platform->socialPlatform()->label()]),
            ],
        ]);
    }

    private function result(bool $success, string $message): Response
    {
        Inertia::setRootView('guest');

        return Inertia::render('guest/ConnectionResult', [
            'success' => $success,
            'message' => $message,
            'title' => $success ? __('guest_connections.success_title') : __('guest_connections.error_title'),
        ]);
    }
}

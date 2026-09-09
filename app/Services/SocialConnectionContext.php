<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Partner\ConnectionAuthorizationStatus;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Partner\GuestConnectionGrantUnavailableException;
use App\Jobs\DeliverPartnerConnectionWebhook;
use App\Models\ConnectionAuthorizationRequest;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocialConnectionContext
{
    public function resolveWorkspace(Request $request, Platform $platform): ?Workspace
    {
        $workspaceId = $request->session()->get('social_connect_workspace');

        if (! $workspaceId) {
            return null;
        }

        $workspace = Workspace::find($workspaceId);

        if (! $workspace) {
            return null;
        }

        if ($request->session()->get('social_connection_mode') === 'guest') {
            $guestRequest = $this->guestAuthorizationRequest($request, $platform);

            return $guestRequest?->workspace_id === $workspace->id ? $workspace : null;
        }

        return $request->user()?->can('manageAccounts', $workspace) ? $workspace : null;
    }

    public function guestAuthorizationRequest(Request $request, Platform $platform): ?ConnectionAuthorizationRequest
    {
        $guestSession = $request->session()->get('guest_social_connection');

        if (! is_array($guestSession)) {
            return null;
        }

        $authorizationRequest = ConnectionAuthorizationRequest::query()
            ->whereKey(data_get($guestSession, 'request_id'))
            ->where('status', ConnectionAuthorizationStatus::Claimed->value)
            ->where('expires_at', '>', now())
            ->first();

        if (
            ! $authorizationRequest
            || $authorizationRequest->platform->socialPlatform() !== $platform
            || ! is_string(data_get($guestSession, 'claim_secret'))
            || ! $authorizationRequest->claim_digest
            || ! hash_equals($authorizationRequest->claim_digest, hash('sha256', data_get($guestSession, 'claim_secret')))
        ) {
            return null;
        }

        return $authorizationRequest;
    }

    public function completeGuestConnection(Request $request, SocialAccount $socialAccount): void
    {
        if ($request->session()->get('social_connection_mode') !== 'guest') {
            return;
        }

        $guestSession = $request->session()->get('guest_social_connection');

        if (
            ! is_array($guestSession)
            || ! is_string(data_get($guestSession, 'request_id'))
            || ! is_string(data_get($guestSession, 'claim_secret'))
        ) {
            throw new GuestConnectionGrantUnavailableException('Guest connection grant is no longer available.');
        }

        DB::transaction(function () use ($guestSession, $socialAccount): void {
            $lockedRequest = ConnectionAuthorizationRequest::query()
                ->whereKey(data_get($guestSession, 'request_id'))
                ->lockForUpdate()
                ->first();

            if (
                ! $lockedRequest
                || $lockedRequest->status !== ConnectionAuthorizationStatus::Claimed
                || $lockedRequest->expires_at->isPast()
                || $lockedRequest->workspace_id !== $socialAccount->workspace_id
                || $lockedRequest->platform->socialPlatform() !== $socialAccount->platform
                || ! $lockedRequest->claim_digest
                || ! hash_equals(
                    $lockedRequest->claim_digest,
                    hash('sha256', data_get($guestSession, 'claim_secret')),
                )
            ) {
                throw new GuestConnectionGrantUnavailableException('Guest connection grant is no longer available.');
            }

            $eventId = $lockedRequest->webhook_event_id ?? (string) Str::uuid();
            $lockedRequest->update([
                'status' => ConnectionAuthorizationStatus::Completed,
                'completed_at' => now(),
                'social_account_id' => $socialAccount->id,
                'webhook_event_id' => $eventId,
                'audit_log' => [...($lockedRequest->audit_log ?? []), [
                    'event' => 'completed',
                    'at' => now()->toIso8601String(),
                    'social_account_id' => $socialAccount->id,
                ]],
            ]);

            DeliverPartnerConnectionWebhook::dispatch($lockedRequest->id)->afterCommit();
        });

        $request->session()->forget('guest_social_connection');
        $request->session()->put('guest_connection_completed', true);
    }

    public function recordGuestEvent(Request $request, Platform $platform, string $event): void
    {
        if ($request->session()->get('social_connection_mode') !== 'guest') {
            return;
        }

        $authorizationRequest = $this->guestAuthorizationRequest($request, $platform);

        if (! $authorizationRequest) {
            return;
        }

        $authorizationRequest->update([
            'audit_log' => [...($authorizationRequest->audit_log ?? []), [
                'event' => $event,
                'at' => now()->toIso8601String(),
            ]],
        ]);
    }
}

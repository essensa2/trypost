<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Models\Workspace;
use App\Services\Social\TokenRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class TikTokController extends SocialController
{
    protected string $driver = 'tiktok';

    protected SocialPlatform $platform = SocialPlatform::TikTok;

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        session(['social_reconnect_id' => null]);

        return $this->redirectToProvider($request, $this->driver, $this->scopes());
    }

    public function callback(Request $request): InertiaResponse
    {
        $workspaceId = session('social_connect_workspace');

        if (! $workspaceId) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $workspace = Workspace::find($workspaceId);

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        if ($request->filled('error')) {
            Log::warning('TikTok OAuth authorization denied', [
                'error' => $request->string('error')->toString(),
                'description' => TokenRedactor::redact($request->string('error_description')->toString()),
                'log_id' => $request->string('log_id')->toString(),
            ]);

            $message = match ($request->string('error')->toString()) {
                'invalid_scope' => __('accounts.popup_callback.tiktok_invalid_scope'),
                'redirect_uri_mismatch' => __('accounts.popup_callback.tiktok_redirect_mismatch'),
                default => __('accounts.popup_callback.tiktok_authorization_failed'),
            };

            return $this->popupCallback(false, $message, $this->platform->value);
        }

        try {
            $socialUser = Socialite::driver($this->driver)
                ->scopes($this->scopes())
                ->user();

            if (! in_array('video.publish', $socialUser->approvedScopes ?? [], true)) {
                return $this->popupCallback(false, __('accounts.popup_callback.tiktok_publish_permission_missing'), $this->platform->value);
            }

            $username = $socialUser->getNickname();
            $avatarPath = uploadFromUrl($socialUser->getAvatar());

            $workspace->socialAccounts()->updateOrCreate(
                [
                    'platform' => $this->platform->value,
                    'platform_user_id' => $socialUser->getId(),
                ],
                [
                    'username' => $username,
                    'display_name' => $socialUser->getName(),
                    'avatar_url' => $avatarPath,
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
                    'scopes' => $socialUser->approvedScopes ?? null,
                    'status' => Status::Connected,
                    'error_message' => null,
                    'disconnected_at' => null,
                ],
            );

            session()->forget('social_reconnect_id');

            return $this->popupCallback(true, __('accounts.popup_callback.connected'), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('TikTok OAuth Error', [
                'error' => TokenRedactor::redact($e->getMessage()),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    /**
     * @return array<int, string>
     */
    private function scopes(): array
    {
        return config('trypost.platforms.tiktok.scopes', ['user.info.basic', 'video.publish']);
    }
}

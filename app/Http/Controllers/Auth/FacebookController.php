<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\ConnectionAuthorizationRequest;
use App\Services\Social\TokenRedactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class FacebookController extends SocialController
{
    protected string $driver = 'facebook';

    protected SocialPlatform $platform = SocialPlatform::Facebook;

    protected array $scopes = [
        'public_profile',
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        'read_insights',
    ];

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        session([
            'social_connect_workspace' => $workspace->id,
            'social_reconnect_id' => null,
            'social_connection_mode' => 'user',
        ]);

        return Inertia::location(
            Socialite::driver($this->driver)
                ->usingGraphVersion($this->graphVersion())
                ->setScopes($this->scopes)
                ->redirect()
                ->getTargetUrl()
        );
    }

    public function guestConnect(
        Request $request,
        ConnectionAuthorizationRequest $connectionAuthorizationRequest,
    ): Response {
        $this->ensurePlatformEnabled();

        abort_unless($connectionAuthorizationRequest->platform->socialPlatform() === $this->platform, 404);

        session([
            'social_connect_workspace' => $connectionAuthorizationRequest->workspace_id,
            'social_reconnect_id' => null,
            'social_connection_mode' => 'guest',
        ]);

        $this->recordGuestConnectionEvent($request, $this->platform, 'oauth_started');

        return Inertia::location(
            Socialite::driver($this->driver)
                ->usingGraphVersion($this->graphVersion())
                ->setScopes($this->scopes)
                ->redirect()
                ->getTargetUrl()
        );
    }

    public function callback(Request $request): InertiaResponse|RedirectResponse
    {
        $workspaceId = session('social_connect_workspace');

        if (! $workspaceId) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $workspace = $this->connectionWorkspace($request, $this->platform);

        if (! $workspace) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        try {
            $socialUser = Socialite::driver($this->driver)->usingGraphVersion($this->graphVersion())->user();

            // Trigger public_profile and pages_show_list API calls
            // These calls are needed for Meta app review permission verification
            Http::get(config('trypost.platforms.facebook.graph_api').'/me', [
                'fields' => 'id,name',
                'access_token' => $socialUser->token,
            ]);

            // Fetch pages the user manages
            $pages = $this->fetchPages($socialUser->token);

            if ($pages === null) {
                return $this->popupCallback(false, __('accounts.popup_callback.facebook_pages_unavailable'), $this->platform->value);
            }

            if (empty($pages)) {
                return $this->popupCallback(false, __('accounts.popup_callback.no_facebook_pages'), $this->platform->value);
            }

            // If only one page, connect directly
            if (count($pages) === 1) {
                $page = $pages[0];
                $avatarPath = uploadFromUrl(data_get($page, 'picture'));

                DB::transaction(function () use ($request, $workspace, $page, $avatarPath, $socialUser): void {
                    $socialAccount = $workspace->socialAccounts()->updateOrCreate(
                        [
                            'platform' => $this->platform->value,
                            'platform_user_id' => data_get($page, 'id'),
                        ],
                        [
                            'username' => data_get($page, 'username', null),
                            'display_name' => data_get($page, 'name'),
                            'avatar_url' => $avatarPath,
                            'access_token' => data_get($page, 'access_token'),
                            'refresh_token' => null,
                            'token_expires_at' => null,
                            'scopes' => $this->scopes,
                            'status' => Status::Connected,
                            'error_message' => null,
                            'disconnected_at' => null,
                            'meta' => [
                                'page_id' => data_get($page, 'id'),
                                'user_id' => $socialUser->getId(),
                                'user_token' => $socialUser->token,
                            ],
                        ],
                    );

                    $this->completeGuestConnection($request, $socialAccount);
                });

                return $this->popupCallback(true, __('accounts.popup_callback.connected'), $this->platform->value);
            }

            // Multiple pages - store data and show selection
            session([
                'facebook_oauth' => [
                    'user_token' => $socialUser->token,
                    'user_id' => $socialUser->getId(),
                    'pages' => $pages,
                ],
            ]);

            return redirect()->route('app.social.facebook.select-page');
        } catch (NetworkAlreadyConnectedException) {
            return $this->popupCallback(false, __('accounts.popup_callback.network_taken'), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Facebook OAuth Error', [
                'error' => TokenRedactor::redact($e->getMessage()),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    public function selectPage(Request $request)
    {
        $oauthData = session('facebook_oauth');
        $workspaceId = session('social_connect_workspace');

        if (! $oauthData || ! $workspaceId) {
            session()->flash('flash.banner', __('accounts.flash.session_expired'));
            session()->flash('flash.bannerStyle', 'danger');

            return redirect()->route('app.accounts');
        }

        $workspace = $this->connectionWorkspace($request, $this->platform);

        if (! $workspace) {
            session()->flash('flash.banner', __('accounts.flash.workspace_not_found'));
            session()->flash('flash.bannerStyle', 'danger');

            return redirect()->route('app.accounts');
        }

        $pages = collect(data_get($oauthData, 'pages'))
            ->map(fn ($page) => Arr::except($page, ['access_token']))
            ->toArray();

        return Inertia::render('accounts/FacebookPageSelect', [
            'workspace' => $workspace->only(['id', 'name']),
            'pages' => $pages,
            'guestConnection' => session('social_connection_mode') === 'guest',
        ]);
    }

    public function select(Request $request): InertiaResponse
    {
        $request->validate([
            'page_id' => 'required|string',
        ]);

        $oauthData = session('facebook_oauth');
        $workspaceId = session('social_connect_workspace');

        if (! $oauthData || ! $workspaceId) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $workspace = $this->connectionWorkspace($request, $this->platform);

        if (! $workspace) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        try {
            $selectedPage = collect(data_get($oauthData, 'pages'))->firstWhere('id', $request->page_id);

            if (! $selectedPage) {
                return $this->popupCallback(false, __('accounts.popup_callback.page_not_found'), $this->platform->value);
            }

            $avatarPath = uploadFromUrl(data_get($selectedPage, 'picture'));
            $reconnectId = data_get($oauthData, 'reconnect_id');
            $reconnected = DB::transaction(function () use ($request, $workspace, $selectedPage, $avatarPath, $oauthData, $reconnectId): bool {
                if ($reconnectId) {
                    $existingAccount = $workspace->socialAccounts()->find($reconnectId);

                    if ($existingAccount) {
                        $existingAccount->update([
                            'platform_user_id' => data_get($selectedPage, 'id'),
                            'username' => data_get($selectedPage, 'username') ?? null,
                            'display_name' => data_get($selectedPage, 'name'),
                            'avatar_url' => $avatarPath,
                            'access_token' => data_get($selectedPage, 'access_token'),
                            'refresh_token' => null,
                            'token_expires_at' => null,
                            'scopes' => $this->scopes,
                            'meta' => [
                                'page_id' => data_get($selectedPage, 'id'),
                                'user_id' => data_get($oauthData, 'user_id'),
                                'user_token' => data_get($oauthData, 'user_token'),
                            ],
                        ]);
                        $existingAccount->markAsConnected();
                        $this->completeGuestConnection($request, $existingAccount);

                        return true;
                    }
                }

                $socialAccount = $workspace->socialAccounts()->updateOrCreate(
                    [
                        'platform' => $this->platform->value,
                        'platform_user_id' => data_get($selectedPage, 'id'),
                    ],
                    [
                        'username' => data_get($selectedPage, 'username') ?? null,
                        'display_name' => data_get($selectedPage, 'name'),
                        'avatar_url' => $avatarPath,
                        'access_token' => data_get($selectedPage, 'access_token'),
                        'refresh_token' => null,
                        'token_expires_at' => null,
                        'scopes' => $this->scopes,
                        'status' => Status::Connected,
                        'error_message' => null,
                        'disconnected_at' => null,
                        'meta' => [
                            'page_id' => data_get($selectedPage, 'id'),
                            'user_id' => data_get($oauthData, 'user_id'),
                            'user_token' => data_get($oauthData, 'user_token'),
                        ],
                    ],
                );

                $this->completeGuestConnection($request, $socialAccount);

                return false;
            });

            session()->forget(['facebook_oauth', 'social_reconnect_id']);

            return $this->popupCallback(
                true,
                $reconnected ? __('accounts.popup_callback.reconnected') : __('accounts.popup_callback.connected'),
                $this->platform->value,
            );
        } catch (NetworkAlreadyConnectedException) {
            return $this->popupCallback(false, __('accounts.popup_callback.network_taken'), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Facebook page selection error', [
                'error' => TokenRedactor::redact($e->getMessage()),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting_page'), $this->platform->value);
        }
    }

    private function fetchPages(string $userToken): ?array
    {
        try {
            $response = Http::get(config('trypost.platforms.facebook.graph_api').'/me/accounts', [
                'access_token' => $userToken,
                'fields' => 'id,name,username,picture{url},access_token',
            ]);

            if ($response->failed()) {
                Log::error('Facebook pages fetch failed', [
                    'status' => $response->status(),
                    'body' => TokenRedactor::redact($response->body()),
                ]);

                return null;
            }

            $data = $response->json();
            $pages = data_get($data, 'data');

            if (! is_array($pages)) {
                Log::error('Facebook pages fetch returned an invalid response');

                return null;
            }

            return collect($pages)->map(fn ($page) => [
                'id' => data_get($page, 'id'),
                'name' => data_get($page, 'name'),
                'username' => data_get($page, 'username', null),
                'picture' => data_get($page, 'picture.data.url'),
                'access_token' => data_get($page, 'access_token'),
            ])->toArray();
        } catch (\Exception $e) {
            Log::error('Facebook pages fetch error', [
                'error' => TokenRedactor::redact($e->getMessage()),
            ]);

            return null;
        }
    }

    private function graphVersion(): string
    {
        return basename((string) parse_url((string) config('trypost.platforms.facebook.graph_api'), PHP_URL_PATH));
    }
}

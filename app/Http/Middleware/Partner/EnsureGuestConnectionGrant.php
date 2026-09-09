<?php

declare(strict_types=1);

namespace App\Http\Middleware\Partner;

use App\Models\ConnectionAuthorizationRequest;
use App\Services\SocialConnectionContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuestConnectionGrant
{
    public function __construct(private SocialConnectionContext $socialConnectionContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authorizationRequest = $request->route('connectionAuthorizationRequest');

        if (
            ! $authorizationRequest instanceof ConnectionAuthorizationRequest
            || ! $this->socialConnectionContext->guestAuthorizationRequest(
                $request,
                $authorizationRequest->platform->socialPlatform(),
            )
            || $request->session()->get('guest_social_connection.request_id') !== $authorizationRequest->id
        ) {
            abort(Response::HTTP_GONE, __('guest_connections.invalid_or_expired'));
        }

        return $next($request);
    }
}

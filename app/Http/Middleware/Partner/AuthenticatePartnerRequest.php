<?php

declare(strict_types=1);

namespace App\Http\Middleware\Partner;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePartnerRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = (string) config('services.ais.partner_token');
        $providedToken = (string) $request->bearerToken();

        if ($configuredToken === '' || $providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}

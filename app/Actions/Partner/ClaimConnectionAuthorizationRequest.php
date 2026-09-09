<?php

declare(strict_types=1);

namespace App\Actions\Partner;

use App\Enums\Partner\ConnectionAuthorizationStatus;
use App\Models\ConnectionAuthorizationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClaimConnectionAuthorizationRequest
{
    /** @return array{authorization_request: ConnectionAuthorizationRequest, claim_secret: string}|null */
    public function execute(string $token, ?string $existingClaimSecret = null): ?array
    {
        return DB::transaction(function () use ($token, $existingClaimSecret): ?array {
            $authorizationRequest = ConnectionAuthorizationRequest::query()
                ->where('token_digest', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $authorizationRequest) {
                return null;
            }

            if (
                in_array($authorizationRequest->status, [ConnectionAuthorizationStatus::Pending, ConnectionAuthorizationStatus::Claimed], true)
                && $authorizationRequest->expires_at->isPast()
            ) {
                $authorizationRequest->update([
                    'status' => ConnectionAuthorizationStatus::Expired,
                    'audit_log' => [...($authorizationRequest->audit_log ?? []), [
                        'event' => 'expired',
                        'at' => now()->toIso8601String(),
                    ]],
                ]);

                return null;
            }

            if ($authorizationRequest->status === ConnectionAuthorizationStatus::Claimed) {
                if (
                    $existingClaimSecret
                    && $authorizationRequest->claim_digest
                    && hash_equals($authorizationRequest->claim_digest, hash('sha256', $existingClaimSecret))
                ) {
                    return [
                        'authorization_request' => $authorizationRequest,
                        'claim_secret' => $existingClaimSecret,
                    ];
                }

                return null;
            }

            if ($authorizationRequest->status !== ConnectionAuthorizationStatus::Pending) {
                return null;
            }

            $claimSecret = Str::random(80);
            $authorizationRequest->update([
                'claim_digest' => hash('sha256', $claimSecret),
                'status' => ConnectionAuthorizationStatus::Claimed,
                'claimed_at' => now(),
                'audit_log' => [...($authorizationRequest->audit_log ?? []), [
                    'event' => 'claimed',
                    'at' => now()->toIso8601String(),
                ]],
            ]);

            return [
                'authorization_request' => $authorizationRequest,
                'claim_secret' => $claimSecret,
            ];
        });
    }
}

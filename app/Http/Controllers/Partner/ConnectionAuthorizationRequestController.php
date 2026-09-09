<?php

declare(strict_types=1);

namespace App\Http\Controllers\Partner;

use App\Actions\Partner\CreateConnectionAuthorizationRequest;
use App\Enums\Partner\ConnectionAuthorizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Partner\StoreConnectionAuthorizationRequest;
use App\Http\Resources\Partner\SocialAccountResource;
use App\Models\ConnectionAuthorizationRequest;
use App\Models\PartnerWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ConnectionAuthorizationRequestController extends Controller
{
    public function store(
        StoreConnectionAuthorizationRequest $request,
        CreateConnectionAuthorizationRequest $createConnectionAuthorizationRequest,
    ): JsonResponse {
        $validated = $request->validated();
        $partnerWorkspace = PartnerWorkspace::query()
            ->where('partner_key', 'ais')
            ->where('workspace_id', $validated['workspace_id'])
            ->firstOrFail();

        $result = $createConnectionAuthorizationRequest->execute($partnerWorkspace, $validated);

        return response()->json(
            $this->responseData($result['authorization_request'], $result['token']),
            $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    public function show(
        string $externalRequestId,
        CreateConnectionAuthorizationRequest $createConnectionAuthorizationRequest,
    ): JsonResponse {
        $authorizationRequest = $this->authorizationRequest($externalRequestId);
        $createConnectionAuthorizationRequest->expireIfNeeded($authorizationRequest);

        return response()->json($this->responseData($authorizationRequest->fresh('socialAccount')));
    }

    public function destroy(string $externalRequestId): JsonResponse
    {
        $authorizationRequest = DB::transaction(function () use ($externalRequestId): ConnectionAuthorizationRequest {
            $authorizationRequest = ConnectionAuthorizationRequest::query()
                ->whereHas('partnerWorkspace', fn ($query) => $query->where('partner_key', 'ais'))
                ->where('external_request_id', $externalRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($authorizationRequest->status === ConnectionAuthorizationStatus::Completed) {
                return $authorizationRequest;
            }

            if (! in_array($authorizationRequest->status, [ConnectionAuthorizationStatus::Expired, ConnectionAuthorizationStatus::Revoked], true)) {
                $authorizationRequest->update([
                    'status' => ConnectionAuthorizationStatus::Revoked,
                    'audit_log' => [...($authorizationRequest->audit_log ?? []), [
                        'event' => 'revoked',
                        'at' => now()->toIso8601String(),
                    ]],
                ]);
            }

            return $authorizationRequest;
        });

        if ($authorizationRequest->status === ConnectionAuthorizationStatus::Completed) {
            return response()->json([
                'message' => 'A completed connection request cannot be revoked.',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function authorizationRequest(string $externalRequestId): ConnectionAuthorizationRequest
    {
        return ConnectionAuthorizationRequest::with('socialAccount')
            ->whereHas('partnerWorkspace', fn ($query) => $query->where('partner_key', 'ais'))
            ->where('external_request_id', $externalRequestId)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function responseData(ConnectionAuthorizationRequest $authorizationRequest, ?string $token = null): array
    {
        $authorizationRequest->loadMissing('socialAccount');

        return [
            'external_request_id' => $authorizationRequest->external_request_id,
            'workspace_id' => $authorizationRequest->workspace_id,
            'platform' => $authorizationRequest->platform->value,
            'status' => $authorizationRequest->status->value,
            'expires_at' => $authorizationRequest->expires_at->toIso8601String(),
            'completed_at' => $authorizationRequest->completed_at?->toIso8601String(),
            'authorization_url' => $token ? route('guest.connections.entry').'#token='.$token : null,
            'social_account' => $authorizationRequest->socialAccount
                ? (new SocialAccountResource($authorizationRequest->socialAccount))->resolve()
                : null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Partner;

use App\Actions\Partner\IssueWorkspaceApiCredential;
use App\Actions\Partner\ProvisionPartnerWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Partner\StorePartnerWorkspaceRequest;
use App\Http\Resources\Partner\SocialAccountResource;
use App\Models\PartnerWorkspace;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceController extends Controller
{
    public function store(
        StorePartnerWorkspaceRequest $request,
        ProvisionPartnerWorkspace $provisionPartnerWorkspace,
    ): JsonResponse {
        $result = $provisionPartnerWorkspace->execute($request->validated());
        $partnerWorkspace = $result['partner_workspace'];

        return response()->json([
            'workspace_id' => $partnerWorkspace->workspace_id,
            'external_project_id' => $partnerWorkspace->external_project_id,
            'name' => $partnerWorkspace->project_name,
            'created' => $result['created'],
            'api_key' => $result['api_key'],
        ], $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function rotateCredential(
        string $workspace,
        IssueWorkspaceApiCredential $issueWorkspaceApiCredential,
    ): JsonResponse {
        $partnerWorkspace = $this->partnerWorkspace($workspace);

        return response()->json([
            'workspace_id' => $partnerWorkspace->workspace_id,
            'api_key' => $issueWorkspaceApiCredential->execute($partnerWorkspace),
        ]);
    }

    public function socialAccounts(string $workspace): JsonResponse
    {
        $partnerWorkspace = $this->partnerWorkspace($workspace);
        $accounts = $partnerWorkspace->workspace->socialAccounts()->orderBy('created_at')->get();

        return response()->json([
            'workspace_id' => $partnerWorkspace->workspace_id,
            'social_accounts' => SocialAccountResource::collection($accounts)->resolve(),
        ]);
    }

    private function partnerWorkspace(string $workspaceId): PartnerWorkspace
    {
        return PartnerWorkspace::with('workspace')
            ->where('partner_key', 'ais')
            ->where('workspace_id', $workspaceId)
            ->firstOrFail();
    }
}

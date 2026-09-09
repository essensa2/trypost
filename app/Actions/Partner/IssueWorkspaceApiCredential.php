<?php

declare(strict_types=1);

namespace App\Actions\Partner;

use App\Models\AccessToken;
use App\Models\PartnerWorkspace;
use App\Models\User;
use RuntimeException;

class IssueWorkspaceApiCredential
{
    public function execute(PartnerWorkspace $partnerWorkspace): string
    {
        $serviceUser = User::find(config('services.ais.service_user_id'));

        if (! $serviceUser || $serviceUser->account_id !== $partnerWorkspace->workspace->account_id) {
            throw new RuntimeException('AIS service user is not configured for the partner workspace account.');
        }

        if ($partnerWorkspace->api_token_id) {
            AccessToken::query()
                ->whereKey($partnerWorkspace->api_token_id)
                ->where('workspace_id', $partnerWorkspace->workspace_id)
                ->update(['revoked' => true]);
        }

        $issuedToken = $serviceUser->createToken("AIS project {$partnerWorkspace->external_project_id}");
        $token = AccessToken::findOrFail($issuedToken->token->id);

        $token->forceFill([
            'workspace_id' => $partnerWorkspace->workspace_id,
            'name' => "AIS project {$partnerWorkspace->external_project_id}",
            'expires_at' => null,
        ])->saveQuietly();

        $partnerWorkspace->forceFill(['api_token_id' => $token->id])->save();

        return $issuedToken->accessToken;
    }
}

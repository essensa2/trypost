<?php

declare(strict_types=1);

namespace App\Actions\Partner;

use App\Enums\UserWorkspace\Role;
use App\Models\PartnerWorkspace;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProvisionPartnerWorkspace
{
    public function __construct(private IssueWorkspaceApiCredential $issueWorkspaceApiCredential) {}

    /**
     * @param  array{external_project_id: string, name: string, locale?: string}  $data
     * @return array{partner_workspace: PartnerWorkspace, created: bool, api_key: string|null}
     */
    public function execute(array $data): array
    {
        $serviceUser = User::with('account')->find(config('services.ais.service_user_id'));

        if (! $serviceUser?->account) {
            throw new RuntimeException('AIS service user is not configured.');
        }

        $existing = PartnerWorkspace::query()
            ->where('partner_key', 'ais')
            ->where('external_project_id', $data['external_project_id'])
            ->first();

        if ($existing) {
            return [
                'partner_workspace' => $existing->load('workspace'),
                'created' => false,
                'api_key' => null,
            ];
        }

        $apiKey = null;
        $partnerWorkspace = DB::transaction(function () use ($data, $serviceUser, &$apiKey): PartnerWorkspace {
            $workspace = Workspace::create([
                'account_id' => $serviceUser->account_id,
                'user_id' => $serviceUser->id,
                'name' => $data['name'],
                'content_language' => $data['locale'] ?? 'en',
            ]);

            $workspace->members()->attach($serviceUser->id, ['role' => Role::Admin->value]);

            $partnerWorkspace = PartnerWorkspace::create([
                'workspace_id' => $workspace->id,
                'partner_key' => 'ais',
                'external_project_id' => $data['external_project_id'],
                'project_name' => $data['name'],
                'locale' => $data['locale'] ?? 'en',
            ])->load('workspace');

            $apiKey = $this->issueWorkspaceApiCredential->execute($partnerWorkspace);

            return $partnerWorkspace;
        });

        $serviceUser->account->syncWorkspaceQuantity();

        return [
            'partner_workspace' => $partnerWorkspace,
            'created' => true,
            'api_key' => $apiKey,
        ];
    }
}

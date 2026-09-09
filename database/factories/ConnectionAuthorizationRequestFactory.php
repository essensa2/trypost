<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Partner\ConnectionAuthorizationStatus;
use App\Enums\Partner\ConnectionPlatform;
use App\Models\ConnectionAuthorizationRequest;
use App\Models\PartnerWorkspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConnectionAuthorizationRequest> */
class ConnectionAuthorizationRequestFactory extends Factory
{
    protected $model = ConnectionAuthorizationRequest::class;

    public function definition(): array
    {
        return [
            'partner_workspace_id' => PartnerWorkspace::factory(),
            'workspace_id' => fn (array $attributes): string => PartnerWorkspace::findOrFail($attributes['partner_workspace_id'])->workspace_id,
            'external_request_id' => fake()->uuid(),
            'platform' => ConnectionPlatform::Facebook,
            'token_digest' => hash('sha256', Str::random(64)),
            'status' => ConnectionAuthorizationStatus::Pending,
            'expires_at' => now()->addDays(7),
            'audit_log' => [],
        ];
    }
}

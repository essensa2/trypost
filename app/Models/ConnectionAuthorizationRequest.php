<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Partner\ConnectionAuthorizationStatus;
use App\Enums\Partner\ConnectionPlatform;
use Database\Factories\ConnectionAuthorizationRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectionAuthorizationRequest extends Model
{
    /** @use HasFactory<ConnectionAuthorizationRequestFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'partner_workspace_id',
        'workspace_id',
        'external_request_id',
        'platform',
        'token_digest',
        'claim_digest',
        'status',
        'expires_at',
        'claimed_at',
        'completed_at',
        'social_account_id',
        'webhook_event_id',
        'webhook_attempts',
        'webhook_delivered_at',
        'audit_log',
    ];

    protected $hidden = [
        'token_digest',
        'claim_digest',
    ];

    protected function casts(): array
    {
        return [
            'platform' => ConnectionPlatform::class,
            'status' => ConnectionAuthorizationStatus::class,
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
            'webhook_delivered_at' => 'datetime',
            'audit_log' => 'array',
        ];
    }

    public function partnerWorkspace(): BelongsTo
    {
        return $this->belongsTo(PartnerWorkspace::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}

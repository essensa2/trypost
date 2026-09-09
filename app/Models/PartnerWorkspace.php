<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PartnerWorkspaceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerWorkspace extends Model
{
    /** @use HasFactory<PartnerWorkspaceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'partner_key',
        'external_project_id',
        'project_name',
        'locale',
        'api_token_id',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function connectionAuthorizationRequests(): HasMany
    {
        return $this->hasMany(ConnectionAuthorizationRequest::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Partner;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SocialAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform->value,
            'platform_user_id' => $this->platform_user_id,
            'username' => $this->username,
            'display_name' => $this->display_name,
            'status' => $this->status->value,
            'is_active' => (bool) $this->is_active,
        ];
    }
}

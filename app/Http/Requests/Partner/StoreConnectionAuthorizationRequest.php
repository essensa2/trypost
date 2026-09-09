<?php

declare(strict_types=1);

namespace App\Http\Requests\Partner;

use App\Enums\Partner\ConnectionPlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConnectionAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'uuid'],
            'external_request_id' => ['required', 'uuid'],
            'platform' => ['required', Rule::enum(ConnectionPlatform::class)],
        ];
    }
}

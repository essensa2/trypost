<?php

declare(strict_types=1);

namespace App\Http\Requests\Partner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartnerWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'external_project_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['nullable', 'string', Rule::in(array_keys(config('languages.available', ['en' => 'English'])))],
        ];
    }
}

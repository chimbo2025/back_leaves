<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string', 'max:60', Rule::unique('roles', 'id')],
            'name' => ['required', 'string', 'max:120', Rule::unique('roles', 'name')],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'approval_tier' => ['sometimes', 'nullable', 'integer'],
            'is_final_authority' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'code')],
        ];
    }
}

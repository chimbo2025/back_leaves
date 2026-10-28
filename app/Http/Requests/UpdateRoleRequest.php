<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('role')->id;

        return [
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('roles', 'name')->ignore($id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'approval_tier' => ['sometimes', 'nullable', 'integer'],
            'is_final_authority' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'code')],
        ];
    }
}

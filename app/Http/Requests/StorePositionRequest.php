<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120', Rule::unique('positions', 'title')],
            'department_id' => ['required', Rule::exists('departments', 'id')],
            'zone_id' => ['nullable', Rule::exists('zones', 'id')],
            'tier' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9'],
            'is_final_authority' => ['sometimes', 'boolean'],
            'default_role_ids' => ['nullable', 'array'],
            'default_role_ids.*' => ['string', Rule::exists('roles', 'id')],
        ];
    }
}

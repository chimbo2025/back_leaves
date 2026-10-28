<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('position')->id;

        return [
            'title' => ['sometimes', 'string', 'max:120', Rule::unique('positions', 'title')->ignore($id)],
            'department_id' => ['sometimes', Rule::exists('departments', 'id')],
            'zone_id' => ['nullable', Rule::exists('zones', 'id')],
            'tier' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9'],
            'is_final_authority' => ['sometimes', 'boolean'],
            'default_role_ids' => ['nullable', 'array'],
            'default_role_ids.*' => ['string', Rule::exists('roles', 'id')],
        ];
    }
}

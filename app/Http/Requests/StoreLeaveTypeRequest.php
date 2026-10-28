<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string', 'max:30', Rule::unique('leave_types', 'id')],
            'code' => ['required', 'string', 'max:20', Rule::unique('leave_types', 'code')],
            'name' => ['required', 'string', 'max:120'],
            'swahili' => ['sometimes', 'nullable', 'string', 'max:120'],
            'short' => ['sometimes', 'nullable', 'string', 'max:30'],
            'is_paid_default' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}

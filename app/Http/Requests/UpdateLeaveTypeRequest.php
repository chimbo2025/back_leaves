<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('leaveType')->id;

        return [
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('leave_types', 'code')->ignore($id)],
            'name' => ['sometimes', 'string', 'max:120'],
            'swahili' => ['sometimes', 'nullable', 'string', 'max:120'],
            'short' => ['sometimes', 'nullable', 'string', 'max:30'],
            'is_paid_default' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}

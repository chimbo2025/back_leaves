<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $today = now()->toDateString();

        return [
            'leave_type_id' => ['required', Rule::exists('leave_types', 'id')],
            // The requester proposes the start date (tarehe ya kuanza); the
            // return date is decided by the approver at approval time.
            'start_date' => ['required', 'date', "after_or_equal:{$today}"],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'is_half_day' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_emergency_exception' => ['sometimes', 'boolean'],
            'exception_reason' => ['required_if:is_emergency_exception,true', 'sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.after_or_equal' => 'Leave cannot start in the past.',
            'end_date.after_or_equal' => 'Return date cannot be before the leave start date.',
            'exception_reason.required_if' => 'An exception reason is required for emergency/monitored exceptions.',
        ];
    }
}

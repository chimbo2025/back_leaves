<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'min_duration_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'department_id' => ['sometimes', 'nullable', Rule::exists('departments', 'id')->where(fn ($q) => $q->whereIn('unit_type', ['department', 'office', 'coordination_office', 'regional_office', 'executive_leadership']))],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => ['sometimes', 'array', 'min:1'],
            // Dynamic approval-role catalog replaces hard-coded role strings.
            'steps.*.approval_role_id' => ['nullable', Rule::exists('approval_roles', 'id')],
            'steps.*.fallback_approval_role_id' => ['nullable', Rule::exists('approval_roles', 'id')],
            // Legacy pointer, kept so pre-catalog definitions remain writable.
            'steps.*.role_required' => ['nullable', Rule::exists('roles', 'id')],
            'steps.*.sla_days' => ['required_with:steps', 'integer', 'min:0', 'max:90'],
            'steps.*.is_mandatory' => ['sometimes', 'boolean'],
            'steps.*.allow_same_person_as_previous_steps' => ['sometimes', 'boolean'],
            'steps.*.escalation_after_sla' => ['sometimes', 'boolean'],
            'steps.*.conditions' => ['sometimes', 'nullable', 'array'],
            'steps.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}

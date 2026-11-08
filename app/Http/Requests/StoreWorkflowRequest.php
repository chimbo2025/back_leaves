<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string', 'max:60', Rule::unique('workflow_definitions', 'id')],
            'leave_type_id' => ['required', Rule::exists('leave_types', 'id')],
            'min_duration_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'zone_id' => ['sometimes', 'nullable', Rule::exists('zones', 'id')],
            'department_id' => ['sometimes', 'nullable', Rule::exists('departments', 'id')],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => ['required', 'array', 'min:1'],
            // Dynamic approval-role catalog replaces hard-coded role strings.
            'steps.*.approval_role_id' => ['sometimes', 'nullable', Rule::exists('approval_roles', 'id')],
            'steps.*.fallback_approval_role_id' => ['sometimes', 'nullable', Rule::exists('approval_roles', 'id')],
            'steps.*.fallback_role_id' => ['sometimes', 'nullable', Rule::exists('approval_roles', 'id')],
            'steps.*.assignment_mode' => ['sometimes', Rule::in(['shared_queue', 'assigned_approver'])],
            'steps.*.scope_type' => ['sometimes', Rule::in(['requester_department', 'requester_division', 'requester_unit', 'requester_zone', 'office_wide'])],
            // Legacy pointer, kept so pre-catalog definitions remain writable.
            'steps.*.role_required' => ['nullable', Rule::exists('roles', 'id')],
            'steps.*.sla_days' => ['required', 'integer', 'min:0', 'max:90'],
            'steps.*.is_mandatory' => ['sometimes', 'boolean'],
            'steps.*.allow_same_person_as_previous_steps' => ['sometimes', 'boolean'],
            'steps.*.escalation_after_sla' => ['sometimes', 'boolean'],
            'steps.*.conditions' => ['sometimes', 'nullable', 'array'],
            'steps.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}

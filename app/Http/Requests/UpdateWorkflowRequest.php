<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('workflow_title')) {
            $this->merge(['workflow_title' => trim((string) $this->input('workflow_title'))]);
        }
    }

    public function rules(): array
    {
        return [
            'workflow_title' => ['sometimes', 'required', 'string', 'max:150'],
            'min_duration_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'max_duration_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:730'],
            'zone_id' => ['sometimes', 'nullable', Rule::exists('zones', 'id')],
            'department_id' => ['sometimes', 'nullable', Rule::exists('departments', 'id')],
            'department_ids' => ['sometimes', 'nullable', 'array'],
            'department_ids.*' => ['sometimes', 'nullable', 'string', Rule::exists('departments', 'id')],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => ['sometimes', 'array', 'min:1'],
            // Dynamic approval-role catalog replaces hard-coded role strings.
            'steps.*.approval_role_id' => ['nullable', Rule::exists('approval_roles', 'id')],
            'steps.*.fallback_approval_role_id' => ['nullable', Rule::exists('approval_roles', 'id')],
            'steps.*.fallback_role_id' => ['nullable', Rule::exists('approval_roles', 'id')],
            'steps.*.assignment_mode' => ['sometimes', 'required_with:steps.*.approval_role_id', Rule::in(['shared_queue', 'assigned_approver'])],
            'steps.*.scope_type' => ['sometimes', 'required_with:steps.*.approval_role_id', Rule::in(['requester_department', 'requester_division', 'requester_unit', 'requester_zone', 'office_wide'])],
            // Legacy pointer, kept so pre-catalog definitions remain writable.
            'steps.*.role_required' => ['nullable', Rule::exists('roles', 'id')],
            'steps.*.step_order' => ['sometimes', 'integer', 'min:1', 'distinct'],
            'steps.*.sla_days' => ['required_with:steps', 'integer', 'min:0', 'max:90'],
            'steps.*.is_mandatory' => ['sometimes', 'boolean'],
            'steps.*.allow_same_person_as_previous_steps' => ['sometimes', 'boolean'],
            'steps.*.escalation_after_sla' => ['sometimes', 'boolean'],
            'steps.*.conditions' => ['sometimes', 'nullable', 'array'],
            'steps.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
            // A workflow definition is a rule about roles, never a roster.
            'steps.*.employee_id' => ['prohibited'],
            'steps.*.assignee_id' => ['prohibited'],
            'steps.*.user_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('steps', []) as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }

                if (! empty($step['approval_role_id'])
                    && ! empty($step['fallback_approval_role_id'])
                    && $step['approval_role_id'] === $step['fallback_approval_role_id']) {
                    $validator->errors()->add(
                        "steps.{$index}.fallback_approval_role_id",
                        'The fallback role must be different from the approval role.',
                    );
                }
            }

            $min = $this->input('min_duration_days');
            $max = $this->input('max_duration_days');
            if ($max !== null && $max !== '' && $min !== null && (int) $max < max(1, (int) $min)) {
                $validator->errors()->add(
                    'max_duration_days',
                    'The maximum duration must be greater than or equal to the minimum duration.',
                );
            }
        });
    }
}

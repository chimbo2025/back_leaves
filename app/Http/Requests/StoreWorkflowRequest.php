<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkflowRequest extends FormRequest
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

        // The builder's "Minimum Leave Duration" defaults to 1. Legacy clients
        // (and the pre-simplification modal) sent 0 to mean "any duration"; the
        // two mean the same thing to the resolver, so normalise instead of
        // rejecting a value the old UI considered valid.
        if ($this->has('min_duration_days') && $this->input('min_duration_days') !== null && $this->input('min_duration_days') !== '') {
            $this->merge(['min_duration_days' => max(1, (int) $this->input('min_duration_days'))]);
        }
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string', 'max:60', Rule::unique('workflow_definitions', 'id')],
            'workflow_title' => ['required', 'string', 'max:150'],
            'leave_type_id' => ['required', Rule::exists('leave_types', 'id')],
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
            'steps' => ['required', 'array', 'min:1'],
            // Dynamic approval-role catalog replaces hard-coded role strings.
            'steps.*.approval_role_id' => ['sometimes', 'nullable', Rule::exists('approval_roles', 'id')],
            'steps.*.fallback_approval_role_id' => ['sometimes', 'nullable', Rule::exists('approval_roles', 'id')],
            'steps.*.fallback_role_id' => ['sometimes', 'nullable', Rule::exists('approval_roles', 'id')],
            // New catalog steps carry their routing mode explicitly. Legacy
            // steps (role_required only) are still accepted and defaulted.
            'steps.*.assignment_mode' => ['sometimes', 'required_with:steps.*.approval_role_id', Rule::in(['shared_queue', 'assigned_approver'])],
            'steps.*.scope_type' => ['sometimes', 'required_with:steps.*.approval_role_id', Rule::in(['requester_department', 'requester_division', 'requester_unit', 'requester_zone', 'office_wide'])],
            // Legacy pointer, kept so pre-catalog definitions remain writable.
            'steps.*.role_required' => ['nullable', Rule::exists('roles', 'id')],
            'steps.*.step_order' => ['sometimes', 'integer', 'min:1', 'distinct'],
            'steps.*.sla_days' => ['required', 'integer', 'min:0', 'max:90'],
            'steps.*.is_mandatory' => ['sometimes', 'boolean'],
            'steps.*.allow_same_person_as_previous_steps' => ['sometimes', 'boolean'],
            'steps.*.escalation_after_sla' => ['sometimes', 'boolean'],
            'steps.*.conditions' => ['sometimes', 'nullable', 'array'],
            'steps.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
            // A workflow definition is a rule about roles, never a roster:
            // naming an individual inside a step is rejected outright.
            'steps.*.employee_id' => ['prohibited'],
            'steps.*.assignee_id' => ['prohibited'],
            'steps.*.user_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateStepShape($validator);
            $this->validateDurationRange($validator);
        });
    }

    protected function validateStepShape(Validator $validator): void
    {
        foreach ((array) $this->input('steps', []) as $index => $step) {
            if (! is_array($step)) {
                continue;
            }

            $hasRole = ! empty($step['approval_role_id']) || ! empty($step['role_required']);
            if (! $hasRole) {
                $validator->errors()->add(
                    "steps.{$index}.approval_role_id",
                    'Select an approval role for every step.',
                );
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
    }

    protected function validateDurationRange(Validator $validator): void
    {
        $min = $this->input('min_duration_days');
        $max = $this->input('max_duration_days');

        if ($max !== null && $max !== '' && $min !== null && (int) $max < max(1, (int) $min)) {
            $validator->errors()->add(
                'max_duration_days',
                'The maximum duration must be greater than or equal to the minimum duration.',
            );
        }
    }
}

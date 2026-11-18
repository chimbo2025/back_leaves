<?php

namespace App\Http\Requests;

use App\Models\ApprovalRole;
use App\Models\Employee;
use App\Services\ApprovalRoleHolderService;
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
            'strict_approvers' => ['sometimes', 'boolean'],
            'steps' => ['sometimes', 'array', 'min:1'],
            // Dynamic approval-role catalog replaces hard-coded role strings.
            'steps.*.approval_role_id' => ['nullable', function ($attribute, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }
                $exists = \App\Models\ApprovalRole::where('id', $value)->exists()
                    || \App\Models\Role::where('id', $value)->exists();
                if (! $exists) {
                    $fail('The selected approval role is invalid.');
                }
            }],
            'steps.*.approver_employee_id' => ['sometimes', 'nullable', 'string', Rule::exists('employees', 'id')],
            'steps.*.fallback_approval_role_id' => ['nullable', function ($attribute, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }
                $exists = \App\Models\ApprovalRole::where('id', $value)->exists()
                    || \App\Models\Role::where('id', $value)->exists();
                if (! $exists) {
                    $fail('The selected fallback role is invalid.');
                }
            }],
            'steps.*.fallback_role_id' => ['nullable', function ($attribute, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }
                $exists = \App\Models\ApprovalRole::where('id', $value)->exists()
                    || \App\Models\Role::where('id', $value)->exists();
                if (! $exists) {
                    $fail('The selected fallback role is invalid.');
                }
            }],
            'steps.*.assignment_mode' => ['sometimes', Rule::in(['shared_queue', 'assigned_approver'])],
            'steps.*.scope_type' => ['sometimes', Rule::in(['requester_department', 'requester_division', 'requester_unit', 'requester_zone', 'office_wide'])],
            // Legacy pointer, kept so pre-catalog definitions remain writable.
            'steps.*.role_required' => ['nullable', Rule::exists('roles', 'id')],
            'steps.*.step_order' => ['sometimes', 'integer', 'min:1', 'distinct'],
            'steps.*.sla_days' => ['required_with:steps', 'integer', 'min:0', 'max:90'],
            'steps.*.is_mandatory' => ['sometimes', 'boolean'],
            'steps.*.allow_same_person_as_previous_steps' => ['sometimes', 'boolean'],
            'steps.*.escalation_after_sla' => ['sometimes', 'boolean'],
            'steps.*.conditions' => ['sometimes', 'nullable', 'array'],
            'steps.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
            // A workflow definition is a rule about roles plus one explicit
            // approver; generic person pointers stay prohibited.
            'steps.*.employee_id' => ['prohibited'],
            'steps.*.assignee_id' => ['prohibited'],
            'steps.*.user_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateStepShape($validator);
            $this->validateApprovers($validator);
            $this->validateDuplicateStepOrder($validator);

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

    protected function validateApprovers(Validator $validator): void
    {
        $strict = filter_var($this->input('strict_approvers', false), FILTER_VALIDATE_BOOLEAN);
        $holders = app(ApprovalRoleHolderService::class);

        foreach ((array) $this->input('steps', []) as $index => $step) {
            if (! is_array($step)) {
                continue;
            }

            $roleId = $step['approval_role_id'] ?? null;
            $approverId = $step['approver_employee_id'] ?? null;

            if ($strict && empty($approverId)) {
                $validator->errors()->add(
                    "steps.{$index}.approver_employee_id",
                    'Select an approver for every step.',
                );

                continue;
            }

            if (empty($approverId)) {
                continue;
            }

            if (empty($roleId)) {
                $validator->errors()->add(
                    "steps.{$index}.approver_employee_id",
                    'Select an approval role before choosing an approver.',
                );

                continue;
            }

            $role = ApprovalRole::where('id', $roleId)->orWhere('code', $roleId)->first();
            if (! $role) {
                $systemRole = \App\Models\Role::where('id', $roleId)->first();
                if ($systemRole) {
                    $role = new ApprovalRole([
                        'id' => (string) $systemRole->id,
                        'code' => strtolower((string) $systemRole->id),
                        'name' => $systemRole->name,
                        'resolution_type' => 'employee_approval_roles',
                        'is_active' => true,
                        'is_system_protected' => false,
                    ]);
                }
            }
            if (! $role) {
                continue;
            }

            $employee = Employee::find($approverId);
            if (! $employee || ! $employee->is_active) {
                $validator->errors()->add(
                    "steps.{$index}.approver_employee_id",
                    'The selected approver must be an active employee.',
                );

                continue;
            }

            if (! $holders->employeeHolds($employee, $role)) {
                $validator->errors()->add(
                    "steps.{$index}.approver_employee_id",
                    "{$employee->full_name} does not hold the {$role->name} role.",
                );
            }
        }
    }

    protected function validateDuplicateStepOrder(Validator $validator): void
    {
        $seen = [];

        foreach ((array) $this->input('steps', []) as $index => $step) {
            if (! is_array($step) || ! isset($step['step_order'])) {
                continue;
            }

            $order = (int) $step['step_order'];
            if (isset($seen[$order])) {
                $validator->errors()->add(
                    "steps.{$index}.step_order",
                    'Two steps cannot share the same sequence number.',
                );
            }

            $seen[$order] = true;
        }
    }
}

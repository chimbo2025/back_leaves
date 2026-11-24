<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['department_id', 'division_id', 'unit_id', 'zone_id'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            // The display name stays authoritative; the parts are optional and
            // are derived from it when omitted (the model keeps them in sync).
            'full_name' => ['required', 'string', 'max:120'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'profile_photo_path' => ['nullable', 'string', 'max:2048'],
            'initials' => ['nullable', 'string', 'max:8'],
            'payroll_no' => ['nullable', 'string', 'max:40', Rule::unique('employees', 'payroll_no')],
            'position_id' => ['nullable', Rule::exists('positions', 'id')],
            'department_id' => ['nullable', Rule::exists('departments', 'id')],
            // Divisions/units are placement only; they never scope a workflow.
            'division_id' => ['nullable', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('unit_type', 'division'))],
            'unit_id' => ['nullable', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('unit_type', 'independent_unit'))],
            'zone_id' => ['nullable', Rule::exists('zones', 'id')],
            'gender' => ['nullable', Rule::in(['M', 'F'])],
            'email' => ['nullable', 'email', 'max:120', Rule::unique('users', 'email')],
            'hire_date' => ['required', 'date'],
            'username' => ['nullable', 'string', 'max:60', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'min:8', 'regex:/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['string', Rule::exists('roles', 'id')],
            'supervisor_employee_id' => ['nullable', Rule::exists('employees', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

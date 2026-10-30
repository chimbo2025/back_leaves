<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:120'],
            'initials' => ['sometimes', 'string', 'max:8'],
            'payroll_no' => ['sometimes', 'nullable', 'string', 'max:40', Rule::unique('employees', 'payroll_no')->ignore($this->route('employee'))],
            'position_id' => ['sometimes', 'nullable', Rule::exists('positions', 'id')],
            'department_id' => ['sometimes', Rule::exists('departments', 'id')],
            // Divisions/units are placement only; they never scope a workflow.
            'division_id' => ['sometimes', 'nullable', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('unit_type', 'division'))],
            'unit_id' => ['sometimes', 'nullable', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('unit_type', 'independent_unit'))],
            'zone_id' => ['sometimes', Rule::exists('zones', 'id')],
            'gender' => ['sometimes', Rule::in(['M', 'F'])],
            'email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'hire_date' => ['sometimes', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['string', Rule::exists('roles', 'id')],
        ];
    }
}

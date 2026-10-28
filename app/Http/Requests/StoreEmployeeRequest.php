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

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120'],
            'initials' => ['nullable', 'string', 'max:8'],
            'payroll_no' => ['nullable', 'string', 'max:40', Rule::unique('employees', 'payroll_no')],
            'position_id' => ['required', Rule::exists('positions', 'id')],
            'department_id' => ['required', Rule::exists('departments', 'id')],
            'zone_id' => ['required', Rule::exists('zones', 'id')],
            'gender' => ['nullable', Rule::in(['M', 'F'])],
            'email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')],
            'hire_date' => ['required', 'date'],
            'username' => ['nullable', 'string', 'max:60'],
            'password' => ['required', 'string', 'min:8', 'regex:/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['string', Rule::exists('roles', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delegate_employee_id' => ['required', Rule::exists('employees', 'id')],
            'role_id' => ['required', Rule::exists('roles', 'id'), Rule::in(['SUPERVISOR', 'DEPT_HEAD', 'ZONAL_HEAD'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }
}

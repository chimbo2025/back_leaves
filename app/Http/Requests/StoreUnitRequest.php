<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Services\PermissionService;

class StoreUnitRequest extends FormRequest
{
    public function __construct(protected PermissionService $permissions) {}

    public function authorize(): bool
    {
        return $this->permissions->can($this->user(), 'units', 'create') ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:191', Rule::unique('departments', 'code')->where(fn ($q) => $q->where('unit_type', 'independent_unit')->where('is_active', true))],
            'name' => ['required', 'string', 'max:191'],
            'unit_type' => ['nullable', 'string'],
            'reports_to_unit_id' => ['nullable', 'string', 'max:191', Rule::exists('departments', 'id'), 'sometimes'],
            'parent_department_id' => ['nullable', 'string', 'max:191', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('unit_type', 'department')), 'sometimes'],
            'zone_id' => ['nullable', 'string', 'max:191', Rule::exists('zones', 'id')],
            'head_employee_id' => ['nullable', 'string', 'max:191', Rule::exists('employees', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'unit_type.required' => 'The unit type is required.',
            'unit_type.in' => 'The unit type must be independent_unit.',
            'reports_to_unit_id.exists' => 'Reports-to unit must exist.',
            'code.unique' => 'This unit code is already taken by an active unit.',
        ];
    }
}

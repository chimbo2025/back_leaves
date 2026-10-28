<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatchUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('units.update') ?? false;
    }

    public function rules(): array
    {
        $id = $this->unit?->id;
        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:191', Rule::unique('departments', 'code')->ignore($id)->where(fn ($q) => $q->where('unit_type', 'independent_unit')->where('is_active', true))],
            'name' => ['sometimes', 'string', 'max:191'],
            'unit_type' => ['sometimes', 'string', 'in:independent_unit'],
            'reports_to_unit_id' => ['sometimes', 'nullable', 'string', 'max:191', Rule::exists('departments', 'id')->ignore($this->unit?->id)],
            'parent_department_id' => ['sometimes', 'nullable', 'string', 'max:191', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('unit_type', 'department'))],
            'zone_id' => ['sometimes', 'nullable', 'string', 'max:191', Rule::exists('zones', 'id')],
            'head_employee_id' => ['sometimes', 'nullable', 'string', 'max:191', Rule::exists('employees', 'id')->where(fn ($q) => $q->where('is_active', true), $this->unit?->id)],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
        ];
    }
}

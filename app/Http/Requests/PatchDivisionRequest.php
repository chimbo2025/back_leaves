<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatchDivisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('divisions.update') ?? false;
    }

    public function rules(): array
    {
        $rules = [
            'code' => ['sometimes', 'nullable', 'string', 'max:191', Rule::unique('departments', 'code')->ignore($this->division?->id)->where(fn ($q) => $q->where('unit_type', 'division')->where('is_active', true))],
            'name' => ['sometimes', 'string', 'max:191'],
            'parent_department_id' => ['sometimes', 'nullable', 'string', 'max:191', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('is_active', true), $this->id)],
            'zone_id' => ['sometimes', 'nullable', 'string', 'max:191', Rule::exists('zones', 'id')],
            'head_employee_id' => ['sometimes', 'nullable', 'string', 'max:191', Rule::exists('employees', 'id')->where(fn ($q) => $q->where('is_active', true), $this->id)],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
        ];
        return $rules;
    }
}

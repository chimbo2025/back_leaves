<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Positions are simple job titles (organization refactor). They carry no
 * approval tier, no final-authority flag and no default roles; authority comes
 * from employee_roles and the dynamic approval-role catalog.
 */
class StorePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $departmentId = $this->input('department_id');
        $divisionId = $this->input('division_id');
        $unitId = $this->input('unit_id');

        $uniqueTitle = Rule::unique('positions', 'title')->where(function ($query) use ($departmentId, $divisionId, $unitId) {
            if ($divisionId && $unitId) {
                // If a position is associated with both, its title must be
                // unique in either scope.
                $query->where(function ($scope) use ($divisionId, $unitId) {
                    $scope->where('division_id', $divisionId)->orWhere('unit_id', $unitId);
                });
            } elseif ($unitId) {
                // Independent units own their title namespace regardless of
                // which department the employee record also points to.
                $query->where('unit_id', $unitId);
            } elseif ($divisionId) {
                $query->where('division_id', $divisionId);
            } else {
                $query->where('department_id', $departmentId)
                    ->whereNull('division_id')
                    ->whereNull('unit_id');
            }
        });

        return [
            'code' => ['nullable', 'string', 'max:40', Rule::unique('positions', 'code')],
            'title' => ['required', 'string', 'max:120', $uniqueTitle],
            'title_sw' => ['nullable', 'string', 'max:120'],
            'department_id' => ['required', Rule::exists('departments', 'id')],
            'division_id' => ['nullable', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('unit_type', 'division'))],
            'unit_id' => ['nullable', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('unit_type', 'independent_unit'))],
            'zone_id' => ['nullable', Rule::exists('zones', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

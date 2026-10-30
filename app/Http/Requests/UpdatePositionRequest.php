<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $position = $this->route('position');
        $id = $position->id;
        $departmentId = $this->input('department_id', $position->department_id);
        $divisionId = $this->input('division_id', $position->division_id);
        $unitId = $this->input('unit_id', $position->unit_id);

        $uniqueTitle = Rule::unique('positions', 'title')->ignore($id)
            ->where(function ($query) use ($departmentId, $divisionId, $unitId) {
                if ($unitId) {
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
            'code' => ['sometimes', 'nullable', 'string', 'max:40', Rule::unique('positions', 'code')->ignore($id)],
            'title' => ['sometimes', 'string', 'max:120', $uniqueTitle],
            'title_sw' => ['sometimes', 'nullable', 'string', 'max:120'],
            'department_id' => ['sometimes', Rule::exists('departments', 'id')],
            'division_id' => ['sometimes', 'nullable', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('unit_type', 'division'))],
            'unit_id' => ['sometimes', 'nullable', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('unit_type', 'independent_unit'))],
            'zone_id' => ['sometimes', 'nullable', Rule::exists('zones', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // The unique rule on `title` covers title edits. Also protect a
            // placement-only move where the existing title would collide in
            // the destination department/division/unit.
            if ($this->exists('title') || ! collect(['department_id', 'division_id', 'unit_id'])->contains(fn ($field) => $this->exists($field))) {
                return;
            }

            $position = $this->route('position');
            $departmentId = $this->input('department_id', $position->department_id);
            $divisionId = $this->input('division_id', $position->division_id);
            $unitId = $this->input('unit_id', $position->unit_id);

            $query = DB::table('positions')
                ->where('id', '<>', $position->id)
                ->where('title', $position->title);

            if ($divisionId && $unitId) {
                $query->where(function ($scope) use ($divisionId, $unitId) {
                    $scope->where('division_id', $divisionId)->orWhere('unit_id', $unitId);
                });
            } elseif ($unitId) {
                $query->where('unit_id', $unitId);
            } elseif ($divisionId) {
                $query->where('division_id', $divisionId);
            } else {
                $query->where('department_id', $departmentId)
                    ->whereNull('division_id')
                    ->whereNull('unit_id');
            }

            if ($query->exists()) {
                $validator->errors()->add('title', 'A position with this title already exists in the selected department or section.');
            }
        });
    }
}

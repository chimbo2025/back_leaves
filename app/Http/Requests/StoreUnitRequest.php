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
        return $this->permissions->can($this->user(), 'admin', 'create') ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:191', Rule::unique('departments', 'code')->where(fn ($q) => $q->where('unit_type', 'independent_unit')->where('is_active', true))],
            'name' => ['required', 'string', 'max:191'],
            'name_sw' => ['nullable', 'string', 'max:191'],
            'reports_directly_to_leadership' => ['nullable', 'boolean'],
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
            'code.unique' => 'This unit code is already taken by an active unit.',
        ];
    }
}

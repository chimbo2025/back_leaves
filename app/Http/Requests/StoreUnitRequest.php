<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:191',
            'code' => 'nullable|string|max:191',
            'name_sw' => 'nullable|string|max:191',
            'zone_id' => 'nullable|string|max:191',
            'parent_department_id' => 'nullable|string|max:191',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date',
        ];
    }
}

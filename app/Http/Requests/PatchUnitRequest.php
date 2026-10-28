<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class PatchUnitRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:191',
            'code' => 'sometimes|nullable|string|max:191',
            'name_sw' => 'sometimes|nullable|string|max:191',
            'zone_id' => 'sometimes|nullable|string|max:191',
            'parent_department_id' => 'sometimes|nullable|string|max:191',
            'is_active' => 'sometimes|nullable|boolean',
            'sort_order' => 'sometimes|nullable|integer',
            'effective_from' => 'sometimes|nullable|date',
            'effective_to' => 'sometimes|nullable|date',
        ];
    }
}

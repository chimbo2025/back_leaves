<?php

namespace App\Http\Resources;

use App\Models\LeavePaymentCase;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Payment case for HR-side reviewers (review/approve holders): everything
 * the employee sees plus salary basis, eligibility/calculation snapshots,
 * internal reasons and the full workflow trail. Mirrors the HR-side branch
 * of LeavePaymentController::resource().
 *
 * @mixin LeavePaymentCase
 */
class PaymentCaseHRResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray($request): array
    {
        return array_merge(
            (new PaymentCaseEmployeeResource($this->resource))->toArray($request),
            [
                'employee' => $this->whenLoaded('employee', fn () => [
                    'id' => $this->employee->id,
                    'full_name' => $this->employee->full_name,
                    'department' => $this->employee->department ? [
                        'id' => $this->employee->department->id,
                        'name' => $this->employee->department->name,
                    ] : null,
                    'zone' => $this->employee->zone ? [
                        'id' => $this->employee->zone->id,
                        'name' => $this->employee->zone->name,
                        'short' => $this->employee->zone->short,
                    ] : null,
                ]),
                'salary_basis' => $this->salary_basis,
                'salary_amount' => $this->salary_amount !== null ? (float) $this->salary_amount : null,
                'allowances_included' => $this->allowances_included,
                'allowances_excluded' => $this->allowances_excluded,
                'calculation_snapshot' => $this->calculation_snapshot,
                'eligibility_snapshot' => $this->eligibility_snapshot,
                'cancellation_reason' => $this->cancellation_reason,
                'created_by' => $this->created_by,
                'submitted_by' => $this->submitted_by,
                'hr_reviewed_by' => $this->hr_reviewed_by,
                'hr_reviewed_at' => $this->hr_reviewed_at?->toDateString(),
                'sent_to_accounting_by' => $this->sent_to_accounting_by,
                'sent_to_accounting_at' => $this->sent_to_accounting_at?->toDateString(),
                'processed_by' => $this->processed_by,
                'processing_at' => $this->processing_at?->toDateString(),
                'rejected_by' => $this->rejected_by,
                'rejected_at' => $this->rejected_at?->toDateString(),
                'cancelled_by' => $this->cancelled_by,
                'cancelled_at' => $this->cancelled_at?->toDateString(),
                'accounting_notified_at' => $this->accounting_notified_at?->toDateString(),
            ]
        );
    }
}

<?php

namespace App\Http\Resources;

use App\Models\LeavePaymentCase;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Payment case as its owner sees it: payable facts, own leave reference and
 * own decision outcome. Salary basis, snapshots and other employees' data
 * never appear here (matching LeavePaymentController's non-HR-side rule).
 *
 * @mixin LeavePaymentCase
 */
class PaymentCaseEmployeeResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'payment_type' => $this->payment_type,
            'status' => $this->status,
            'currency' => $this->currency,
            'eligible_amount' => $this->eligible_amount !== null ? (float) $this->eligible_amount : null,
            'calculated_amount' => $this->calculated_amount !== null ? (float) $this->calculated_amount : null,
            'leave_type' => $this->whenLoaded('leaveType', fn () => [
                'id' => $this->leaveType->id,
                'name' => $this->leaveType->name,
            ]),
            'leave_request' => $this->whenLoaded('leaveRequest', fn () => $this->leaveRequest ? [
                'id' => $this->leaveRequest->id,
                'ref' => $this->leaveRequest->ref,
                'status' => $this->leaveRequest->status,
                'start_date' => $this->leaveRequest->start_date?->toDateString(),
                'end_date' => $this->leaveRequest->end_date?->toDateString(),
                'days' => $this->leaveRequest->days !== null ? (float) $this->leaveRequest->days : null,
            ] : null),
            'submitted_at' => $this->submitted_at?->toDateString(),
            'paid_at' => $this->paid_at?->toDateString(),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at?->toDateString(),
        ];
    }
}

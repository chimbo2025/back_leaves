<?php

namespace App\Http\Resources;

use App\Models\LeavePaymentCase;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Payment case for the Accounting audience (receive/process holders without
 * review/approve): payable facts and settlement trail ONLY.
 *
 * Deliberately absent: leave type (a MATERNITY code is medical information),
 * salary basis and amounts, eligibility/calculation snapshots, rejection and
 * cancellation reasons (internal HR commentary), approver identities and
 * comments. Accountants settle money; they never see why it was approved.
 *
 * @mixin LeavePaymentCase
 */
class PaymentCaseAccountingResource extends JsonResource
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
            'leave_request' => $this->whenLoaded('leaveRequest', fn () => $this->leaveRequest ? [
                'id' => $this->leaveRequest->id,
                'ref' => $this->leaveRequest->ref,
                'start_date' => $this->leaveRequest->start_date?->toDateString(),
                'end_date' => $this->leaveRequest->end_date?->toDateString(),
                'days' => $this->leaveRequest->days !== null ? (float) $this->leaveRequest->days : null,
            ] : null),
            'submitted_at' => $this->submitted_at?->toDateString(),
            'sent_to_accounting_at' => $this->sent_to_accounting_at?->toDateString(),
            'processing_at' => $this->processing_at?->toDateString(),
            'paid_at' => $this->paid_at?->toDateString(),
            'created_at' => $this->created_at?->toDateString(),
        ];
    }
}

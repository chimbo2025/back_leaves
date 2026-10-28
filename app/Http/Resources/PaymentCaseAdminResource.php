<?php

namespace App\Http\Resources;

use App\Models\LeavePaymentCase;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Payment case for the final-authority audience: the full HR view plus the
 * linked leave reference facts. Authority holders already hold every code;
 * this resource exists so oversight rows are never silently redacted.
 *
 * @mixin LeavePaymentCase
 */
class PaymentCaseAdminResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray($request): array
    {
        return (new PaymentCaseHRResource($this->resource))->toArray($request);
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['approved', 'rejected', 'returned'])],
            'comments' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'end_date' => ['nullable', 'date'],
            // Ceremony attestation for approvals: the Approvals UI only enables
            // "Grant leave" after a signature is captured, and the API refuses
            // signature-less approvals so the ceremony cannot be skipped by
            // calling the endpoint directly. The value itself is opaque (the
            // server seals the decision with its own hash in
            // LeaveApprovalSignatureService); presence is what is enforced.
            'signature_hash' => ['required_if:decision,approved', 'nullable', 'string', 'max:255'],
            'signature_type' => ['nullable', 'string', Rule::in(['digital', 'wet_ink_reference'])],
        ];
    }
}

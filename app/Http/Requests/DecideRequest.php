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

    /**
     * The `/api/approvals/{id}/approve|reject|return` aliases encode the
     * decision in the URL, so the body does not have to repeat it. Normalise
     * here instead of duplicating the whole validation/authorisation path in
     * three controller methods.
     */
    protected function prepareForValidation(): void
    {
        $map = [
            'approve' => 'approved',
            'reject' => 'rejected',
            'returnForCorrection' => 'returned',
        ];

        $action = $this->route()?->getActionMethod();

        if (isset($map[$action]) && ! $this->has('decision')) {
            $this->merge(['decision' => $map[$action]]);
        }
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['approved', 'rejected', 'returned'])],
            // A rejection or a return must carry its reason: the requester is
            // shown these words (and only these) so they can correct or appeal.
            // Approvals keep comments optional.
            'comments' => [
                'nullable',
                'string',
                'max:4000',
                'required_if:decision,rejected',
                'required_if:decision,returned',
            ],
            'end_date' => ['nullable', 'date'],
            // Ceremony attestation for approvals: the Approvals UI only enables
            // "Grant leave" after a signature is captured, and the API refuses
            // signature-less approvals so the ceremony cannot be skipped by
            // calling the endpoint directly. The value itself is opaque (the
            // server seals the decision with its own hash in
            // LeaveApprovalSignatureService); presence is what is enforced.
            'signature_hash' => ['required_if:decision,approved', 'nullable', 'string', 'max:255'],
            'signature_type' => ['nullable', 'string', Rule::in(['digital', 'wet_ink_reference'])],
            // The drawn/uploaded ceremony image (data URL). Stored under
            // storage/app/signatures/ and linked from the seal row —
            // optional on every decision; sealing never depends on storage.
            'signature_image' => ['nullable', 'string', 'max:4200000'],
            'is_paid' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'comments.required_if' => 'Sababu ya kukataa au kurudisha ombi inahitajika (A reason is required when rejecting or returning a request).',
            'signature_hash.required_if' => 'Sahihi inahitajika kabla ya kuidhinisha (A signature is required before approving).',
        ];
    }
}

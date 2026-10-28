<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeavePolicy;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Versioned policy creation (admin). New versions never edit prior rows —
 * the audit log records the change and the request pins the version at
 * submission time. edit_policy is the granular gate.
 */
class PolicyController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'max_days_per_year' => ['nullable', 'numeric', 'min:0'],
            'max_days_per_request' => ['nullable', 'numeric', 'min:0'],
            'min_tenure_months' => ['nullable', 'integer', 'min:0'],
            'min_notice_days' => ['nullable', 'integer', 'min:0'],
            'requires_documents' => ['sometimes', 'boolean'],
            'required_docs' => ['sometimes', 'array'],
            'required_docs.*' => ['string'],
            'carry_over_max' => ['nullable', 'numeric', 'min:0'],
            'allow_half_day' => ['sometimes', 'boolean'],
            'effective_from' => ['required', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        // grant is enforced by route middleware 'permission:policies.edit_policy'
        $nextVersion = (LeavePolicy::where('leave_type_id', $validated['leave_type_id'])->max('version') ?? 0) + 1;
        $policy = LeavePolicy::create([
            'id' => $validated['leave_type_id'].'_v'.$nextVersion,
            'version' => $nextVersion,
            // created_by/approved_by FK to USERS (nullOnDelete) — the actor's
            // user id, matching the seeded convention (u3/u1), not their
            // employee id, which would violate the constraint.
            'created_by' => $request->user()->id,
            'approved_by' => $request->user()->id,
            ...$validated,
        ]);

        $this->audit->append('leave_policy', $policy->id, 'publish', $request->user()->id, [
            'leave_type_id' => $policy->leave_type_id,
            'version' => $policy->version,
            'max_days_per_request' => $policy->max_days_per_request,
            'effective_from' => $policy->effective_from?->toDateString(),
        ]);

        return response()->json(['data' => $policy->load('leaveType')], 201);
    }
}

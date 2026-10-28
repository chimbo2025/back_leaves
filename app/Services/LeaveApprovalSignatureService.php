<?php

namespace App\Services;

use App\Models\ApprovalAction;
use App\Models\ApprovalSignature;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leave approval signature service (Phase 6).
 *
 * Captures a digital signature for each approval decision. The signature is
 * a SHA-256 hash of a canonical payload that includes the decision, the
 * request reference, the actor, the timestamp, and the policy version. The
 * raw signature value is never stored — only an opaque reference (e.g., HSM
 * token ID) and the hash.
 *
 * This service is called from LeaveRequestService::decide() after the
 * ApprovalAction is created.
 */
class LeaveApprovalSignatureService
{
    public function __construct(
        protected AuditService $audit,
    ) {}

    /**
     * Record a signature for the given approval action.
     *
     * @param  array{ip?:?string, user_agent?:?string, signature_value?:?string, signature_type?:string}  $meta
     */
    public function record(
        ApprovalAction $action,
        Employee $actor,
        LeaveRequest $request,
        array $meta = []
    ): ApprovalSignature {
        $payload = $this->canonicalPayload($action, $actor, $request, $meta);
        $hash = hash('sha256', $payload);

        $signature = DB::transaction(function () use ($action, $actor, $request, $meta, $hash) {
            $sig = ApprovalSignature::create([
                'leave_request_id' => $request->id,
                'approval_action_id' => $action->id,
                'actor_id' => $actor->id,
                'approval_action' => $action->decision,
                'signature_type' => $meta['signature_type'] ?? ApprovalSignature::TYPE_DIGITAL,
                'signature_value' => $meta['signature_value'] ?? null,
                'signature_hash' => $hash,
                'signed_at' => $action->acted_at ?? now(),
                'ip_address' => $meta['ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
                'metadata_json' => [
                    'policy_version' => $request->policy_version,
                    'return_date' => $request->end_date?->toDateString(),
                    'actor_name' => $actor->full_name,
                    'actor_role' => $action->assignment?->assigned_role_id,
                    'decision' => $action->decision,
                    'comments' => $action->comments,
                ],
            ]);

            $this->audit->append('leave_request', (string) $request->id, 'signature.recorded', $actor->user_id, [
                'signature_id' => (string) $sig->id,
                'approval_action_id' => (string) $action->id,
                'decision' => $action->decision,
                'signature_hash' => $hash,
            ]);

            return $sig;
        });

        return $signature;
    }

    /**
     * Build the canonical payload for signature hashing.
     *
     * The payload must be deterministic and include all fields that make
     * the decision unique and tamper-evident.
     */
    protected function canonicalPayload(
        ApprovalAction $action,
        Employee $actor,
        LeaveRequest $request,
        array $meta
    ): string {
        $data = [
            'leave_request_id' => $request->id,
            'leave_request_ref' => $request->ref,
            'approval_action_id' => $action->id,
            'actor_employee_id' => $actor->id,
            'actor_user_id' => $actor->user_id,
            'decision' => $action->decision,
            'acted_at' => $action->acted_at?->toIso8601String(),
            'policy_version' => $request->policy_version,
            'policy_id' => $request->policy_id,
            'return_date' => $request->end_date?->toDateString(),
            'start_date' => $request->start_date->toDateString(),
            'days' => $request->days,
            'signature_type' => $meta['signature_type'] ?? ApprovalSignature::TYPE_DIGITAL,
        ];

        ksort($data);
        $out = '';
        foreach ($data as $k => $v) {
            $out .= $k.'='.(is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $v).';';
        }

        return $out;
    }

    /**
     * Verify a signature against its stored hash.
     */
    public function verify(ApprovalSignature $signature): bool
    {
        // Reconstruct the payload from the stored metadata and related models.
        $action = $signature->approvalAction;
        $request = $signature->leaveRequest;
        $actor = $signature->actor;

        if (! $action || ! $request || ! $actor) {
            return false;
        }

        $payload = [
            'leave_request_id' => $request->id,
            'leave_request_ref' => $request->ref,
            'approval_action_id' => $action->id,
            'actor_employee_id' => $actor->id,
            'actor_user_id' => $actor->user_id,
            'decision' => $action->decision,
            'acted_at' => $action->acted_at?->toIso8601String(),
            'policy_version' => $request->policy_version,
            'policy_id' => $request->policy_id,
            'return_date' => $request->end_date?->toDateString(),
            'start_date' => $request->start_date->toDateString(),
            'days' => $request->days,
            'signature_type' => $signature->signature_type,
        ];

        ksort($payload);
        $canonical = '';
        foreach ($payload as $k => $v) {
            $canonical .= $k.'='.(is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $v).';';
        }

        return hash_equals($signature->signature_hash, hash('sha256', $canonical));
    }

    /**
     * Get all signatures for a leave request.
     *
     * @return Collection<int, ApprovalSignature>
     */
    public function getForRequest(LeaveRequest $request)
    {
        return ApprovalSignature::where('leave_request_id', $request->id)
            ->orderBy('signed_at')
            ->get();
    }
}

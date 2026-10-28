<?php

namespace App\Services;

use App\Models\LeavePolicy;
use App\Models\LeaveRule;
use Carbon\Carbon;

class LeavePolicyResolver
{
    public function resolve(string $leaveTypeId, ?string $date = null): ?LeavePolicy
    {
        $day = Carbon::parse($date ?? now()->toDateString());
        $q = LeavePolicy::query()
            ->where('leave_type_id', $leaveTypeId)
            ->where('effective_from', '<=', $day->toDateString())
            ->where(function ($q2) use ($day) {
                $q2->whereNull('effective_to')->orWhere('effective_to', '>=', $day->toDateString());
            })
            ->whereIn('status', ['active', 'approved'])
            ->orderByDesc('priority')
            ->orderByDesc('version');

        return $q->first();
    }

    /**
     * Resolve policy with its rules (supporting inherited/overridden rules).
     * Returns array with policy + resolved_rules.
     */
    public function resolveWithRules(string $leaveTypeId, ?string $date = null): array
    {
        $policy = $this->resolve($leaveTypeId, $date);
        if (! $policy) {
            return ['policy' => null, 'policy_id' => null, 'policy_version' => null, 'source' => null, 'resolved_rules' => []];
        }
        $rules = LeaveRule::query()
            ->where('leave_policy_id', $policy->id)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->map(function ($r) {
                return [
                    'rule_key' => $r->rule_key,
                    'operator' => $r->operator,
                    'value' => $r->value_json,
                    'condition' => $r->condition_json,
                    'priority' => $r->priority,
                    'is_inherited' => $r->is_inherited,
                    'inherited_from_rule_id' => $r->inherited_from_rule_id,
                    'overridden' => $r->overridden,
                    'source_reference' => $r->source_reference,
                ];
            })->toArray();

        return [
            'policy' => $policy,
            'policy_id' => $policy->id,
            'policy_version' => $policy->version,
            'source' => $policy->source_document,
            'resolved_rules' => $rules,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Audit report service (Phase 7).
 *
 * Provides read-only access to the tamper-evident audit ledger.
 * Includes search, filtering, and chain verification.
 */
class AuditReportService
{
    public function __construct(
        protected AccessScopeService $accessScope,
        protected AuditService $audit,
    ) {}

    /**
     * Search audit logs with filters.
     */
    public function search(User $user, array $filters = []): Builder
    {
        $query = AuditLog::query()->with('actor.employee');

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }
        if (! empty($filters['entity_id'])) {
            $query->where('entity_id', $filters['entity_id']);
        }
        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (! empty($filters['actor_user_id'])) {
            $query->where('actor_user_id', $filters['actor_user_id']);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('entity_type', 'LIKE', "%{$term}%")
                    ->orWhere('action', 'LIKE', "%{$term}%")
                    ->orWhere('entity_id', 'LIKE', "%{$term}%")
                    ->orWhereJsonContains('payload', $term);
            });
        }

        // Audit logs are generally readable by anyone with audit permission.
        // The AccessScopeService is not applied here because audit is a
        // cross-cutting concern; the permission `reports.view_audit` gates access.

        return $query->orderByDesc('id');
    }

    /**
     * Get audit trail for a specific entity.
     */
    public function entityTrail(string $entityType, string $entityId): Builder
    {
        return AuditLog::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->with('actor.employee')
            ->orderBy('id');
    }

    /**
     * Verify the integrity of the entire audit chain.
     *
     * @return array{valid:bool,broken_at_id:int|null,last_hash:string|null,count:int}
     */
    public function verifyChain(): array
    {
        return $this->audit->verify();
    }

    /**
     * Get chain verification status for a specific entity's sub-chain.
     */
    public function verifyEntityChain(string $entityType, string $entityId): array
    {
        $logs = AuditLog::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('id')
            ->get();

        if ($logs->isEmpty()) {
            return ['valid' => true, 'count' => 0];
        }

        $prevHash = AuditService::GENESIS;

        foreach ($logs as $log) {
            $expected = $this->hash($prevHash, $log->payload, $log->created_at);
            if (! hash_equals($log->row_hash, $expected)) {
                return [
                    'valid' => false,
                    'broken_at_id' => $log->id,
                    'last_hash' => $logs->last()->row_hash ?? null,
                    'count' => $logs->count(),
                ];
            }
            if ($log->prev_hash !== null && ! hash_equals($log->prev_hash, $prevHash)) {
                return [
                    'valid' => false,
                    'broken_at_id' => $log->id,
                    'last_hash' => $logs->last()->row_hash ?? null,
                    'count' => $logs->count(),
                ];
            }
            $prevHash = $log->row_hash;
        }

        return [
            'valid' => true,
            'broken_at_id' => null,
            'last_hash' => $logs->last()->row_hash ?? null,
            'count' => $logs->count(),
        ];
    }

    /**
     * Get distinct entity types that have audit logs.
     */
    public function entityTypes(): array
    {
        return AuditLog::distinct()->orderBy('entity_type')->pluck('entity_type')->all();
    }

    /**
     * Get distinct actions for a given entity type.
     */
    public function actionsForEntity(string $entityType): array
    {
        return AuditLog::query()
            ->where('entity_type', $entityType)
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();
    }

    /**
     * Get actors who have performed actions.
     */
    public function actors(): array
    {
        return User::query()
            ->whereIn('id', AuditLog::distinct()->pluck('actor_user_id'))
            ->with('employee')
            ->get()
            ->map(fn ($u) => [
                'user_id' => $u->id,
                'username' => $u->username,
                'employee_id' => $u->employee?->id,
                'employee_name' => $u->employee?->full_name,
            ])
            ->all();
    }

    /**
     * Get audit statistics for a date range.
     *
     * @return array{total:int,by_entity:array<string,int>,by_action:array<string,int>,by_actor:array<string,int>}
     */
    public function statistics(?string $from = null, ?string $to = null): array
    {
        $query = AuditLog::query();

        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        $total = $query->count();

        $byEntity = $query->clone()->select('entity_type', DB::raw('COUNT(*) as count'))
            ->groupBy('entity_type')
            ->pluck('count', 'entity_type')
            ->all();

        $byAction = $query->clone()->select('action', DB::raw('COUNT(*) as count'))
            ->groupBy('action')
            ->pluck('count', 'action')
            ->all();

        $byActor = $query->clone()->select('actor_user_id', DB::raw('COUNT(*) as count'))
            ->groupBy('actor_user_id')
            ->with('actor.employee')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->actor_user_id => [
                'count' => (int) $r->count,
                'username' => $r->actor?->username,
                'employee_name' => $r->actor?->employee?->full_name,
            ]])
            ->all();

        return [
            'total' => $total,
            'by_entity' => $byEntity,
            'by_action' => $byAction,
            'by_actor' => $byActor,
        ];
    }

    // ============================================================
    // Internal hash helper (mirrors AuditService)
    // ============================================================

    protected function hash(string $prevHash, array $payload, CarbonInterface $at): string
    {
        return hash('sha256', $prevHash.'|'.$this->canonicalize($payload).'|'.$at->getTimestamp());
    }

    protected function canonicalize(array $payload): string
    {
        ksort($payload);
        $out = '';
        foreach ($payload as $k => $v) {
            $out .= $k.'='.(is_array($v) ? $this->canonicalize($v) : (string) $v).';';
        }

        return $out;
    }
}

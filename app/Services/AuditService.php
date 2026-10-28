<?php

namespace App\Services;

use App\Models\AuditLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Tamper-evident, hash-chained audit ledger (blueprint §1.5).
 *
 * row_hash = sha256(GENESIS-or-prev_hash . '|' . canonicalPayload . '|' . unixTimestamp)
 *
 * Computed in the application layer (never by a DB trigger) so the chain is
 * unit-testable. Any retroactive edit breaks verify().
 */
class AuditService
{
    public const GENESIS = 'GENESIS';

    public function append(
        string $entityType,
        string $entityId,
        string $action,
        ?string $actorUserId,
        array $payload,
        ?string $reason = null,
        ?CarbonInterface $at = null,
    ): AuditLog {
        // Truncate fractional seconds BEFORE hashing: the timestamp column is
        // whole-second precision, and getTimestamp() on a `.999` instant would
        // hash second N while MySQL stores N+1 — a ms-wide race that broke
        // verify() on later recomputation.
        $at = Carbon::parse(($at ?? Carbon::now())->toDateTimeString());

        $prev = AuditLog::orderByDesc('id')->first();
        $prevHash = $prev?->row_hash ?? self::GENESIS;

        $rowHash = $this->hash($prevHash, $payload, $at);

        return AuditLog::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'actor_user_id' => $actorUserId,
            'payload' => $payload,
            'reason' => $reason,
            'prev_hash' => $prev === null ? null : $prev->row_hash,
            'row_hash' => $rowHash,
            'created_at' => $at,
        ]);
    }

    /**
     * Walk the chain forward and confirm every row's chain is intact.
     *
     * @return array{valid: bool, broken_at_id: int|null, last_hash: string, count: int}
     */
    public function verify(): array
    {
        $logs = AuditLog::orderBy('id')->get();
        $prevHash = self::GENESIS;

        foreach ($logs as $log) {
            $expected = $this->hash($prevHash, $log->payload, Carbon::parse($log->created_at));
            if (hash_equals($log->row_hash, $expected) === false) {
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

    /** Deterministic canonical payload for chaining (keys sorted). */
    protected function canonicalize(array $payload): string
    {
        ksort($payload);
        $out = '';
        foreach ($payload as $k => $v) {
            $out .= $k.'='.(is_array($v) ? $this->canonicalize($v) : (string) $v).';';
        }

        return $out;
    }

    protected function hash(string $prevHash, array $payload, CarbonInterface $at): string
    {
        return hash('sha256', $prevHash.'|'.$this->canonicalize($payload).'|'.$at->getTimestamp());
    }
}

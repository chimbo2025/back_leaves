<?php

namespace App\Services;

use App\Models\Department;
use App\Models\LeaveType;
use App\Models\WorkflowDefinition;
use App\Models\Zone;
use Illuminate\Validation\ValidationException;

/**
 * Pre-save ambiguity guard for workflow definitions.
 *
 * The resolver already refuses to guess between two equally specific active
 * definitions (it throws on submission). That is the last line of defence, not
 * the first: an administrator saving a second Annual-Leave chain that covers
 * the same people only discovers the collision when an employee files a
 * request. This service makes the collision a save-time 422 with a message
 * naming the existing chain.
 *
 * Overlap = same leave type + both active + overlapping effective period +
 * overlapping duration range + overlapping organizational/zone scope.
 *
 * Division and unit scopes are preserved: they are rows of `departments`, so
 * the ancestor-chain comparison below treats them exactly like any other
 * placement node.
 */
class WorkflowOverlapGuard
{
    /**
     * @param  array{leave_type_id:string, workflow_title?:?string, min_duration_days?:int, max_duration_days?:?int, zone_id?:?string, department_id?:?string, effective_from?:?string, effective_to?:?string, is_active?:bool}  $candidate
     * @return WorkflowDefinition|null
     */
    public function findConflict(array $candidate, ?string $excludeId = null): ?WorkflowDefinition
    {
        if (($candidate['is_active'] ?? true) === false) {
            return null;
        }

        $min = max(1, (int) ($candidate['min_duration_days'] ?? 1));
        $max = isset($candidate['max_duration_days']) && $candidate['max_duration_days'] !== null
            ? (int) $candidate['max_duration_days']
            : null;

        $existingRows = WorkflowDefinition::where('leave_type_id', $candidate['leave_type_id'])
            ->where('is_active', true)
            ->when($excludeId, fn ($q) => $q->where('id', '<>', $excludeId))
            ->get();

        foreach ($existingRows as $existing) {
            if (! $this->effectiveOverlap(
                $candidate['effective_from'] ?? null,
                $candidate['effective_to'] ?? null,
                $existing->effective_from?->toDateString(),
                $existing->effective_to?->toDateString(),
            )) {
                continue;
            }

            if (! $this->durationsOverlap(
                $min,
                $max,
                max(1, (int) $existing->min_duration_days),
                $existing->max_duration_days,
            )) {
                continue;
            }

            if (! $this->scopesOverlap(
                $candidate['zone_id'] ?? null,
                $candidate['department_id'] ?? null,
                $existing->zone_id,
                $existing->department_id,
            )) {
                continue;
            }

            return $existing;
        }

        return null;
    }

    /**
     * Throw a field-scoped 422 when the candidate would create ambiguity.
     *
     * A duplicate title inside the overlapping scope is reported on
     * `workflow_title`; any other overlap is reported as a business rule on
     * `workflow`, so the frontend can show it in the banner and next to the
     * title field respectively.
     *
     * @param  array<string, mixed>  $candidate
     */
    public function assertNoConflict(array $candidate, ?string $excludeId = null): void
    {
        $conflict = $this->findConflict($candidate, $excludeId);

        if ($conflict === null) {
            return;
        }

        $typeName = LeaveType::find($candidate['leave_type_id'])?->name
            ?? (string) $candidate['leave_type_id'];
        $existingTitle = $conflict->workflow_title ?: ('workflow v' . $conflict->version);
        $existingScope = $this->scopeLabel($conflict->zone_id, $conflict->department_id);
        $existingDuration = $this->durationLabel(
            (int) $conflict->min_duration_days,
            $conflict->max_duration_days,
        );

        $candidateTitle = trim((string) ($candidate['workflow_title'] ?? ''));
        if ($candidateTitle !== '' && mb_strtolower($candidateTitle) === mb_strtolower(trim((string) $conflict->workflow_title))) {
            throw ValidationException::withMessages([
                'workflow_title' => "The title \"{$candidateTitle}\" is already used by another active {$typeName} workflow covering {$existingScope}.",
            ]);
        }

        $message = "Another active {$typeName} workflow ({$existingTitle}) already covers {$existingScope} for {$existingDuration}. Deactivate or change the existing workflow before creating this one.";

        throw ValidationException::withMessages([
            'workflow' => $message,
            'workflow_title' => $message,
        ]);
    }

    /**
     * Human-readable scope label used by the overlap message and the preview.
     */
    public function scopeLabel(?string $zoneId, ?string $departmentId): string
    {
        if ($departmentId) {
            $dept = Department::find($departmentId);
            $name = $dept?->name ?? $departmentId;

            return $dept?->unit_type === 'division' || $dept?->unit_type === 'independent_unit'
                ? "the {$name} {$dept->unit_type}"
                : "the {$name} department";
        }

        if ($zoneId) {
            $zone = Zone::find($zoneId);

            return 'the ' . ($zone?->name ?? $zoneId) . ' zone';
        }

        return 'the Entire Office';
    }

    public function durationLabel(int $min, ?int $max): string
    {
        $min = max(1, $min);

        if ($max === null) {
            return $min <= 1 ? 'requests of any duration' : "requests of {$min} or more days";
        }

        return $min === $max
            ? "requests of exactly {$min} day" . ($min === 1 ? '' : 's')
            : "requests of {$min}–{$max} days";
    }

    /** Two [min, max|null] ranges overlap unless one ends before the other starts. */
    public function durationsOverlap(int $aMin, ?int $aMax, int $bMin, ?int $bMax): bool
    {
        return $aMin <= ($bMax ?? PHP_INT_MAX) && $bMin <= ($aMax ?? PHP_INT_MAX);
    }

    /**
     * Zone / placement overlap.
     *
     * - Both office-wide: yes.
     * - Office-wide vs zone/placement: yes (the office rule also reaches them).
     * - Two different zones: no.
     * - Two placements: yes when one is the other or an ancestor/descendant,
     *   which is how a division or unit row is covered by its parent chain.
     */
    public function scopesOverlap(?string $aZone, ?string $aDept, ?string $bZone, ?string $bDept): bool
    {
        if ($aDept === null && $bDept === null) {
            return $this->zonesCompatible($aZone, $bZone);
        }

        if ($aDept === null) {
            $deptZone = $bDept ? Department::find($bDept)?->zone_id : null;

            return $this->zonesCompatible($aZone, $bZone ?? $deptZone);
        }

        if ($bDept === null) {
            $deptZone = Department::find($aDept)?->zone_id;

            return $this->zonesCompatible($aZone ?? $deptZone, $bZone);
        }

        return $this->placementContains($aDept, $bDept)
            || $this->placementContains($bDept, $aDept);
    }

    public function effectiveOverlap(?string $aFrom, ?string $aTo, ?string $bFrom, ?string $bTo): bool
    {
        $fromA = $aFrom ?: '1970-01-01';
        $fromB = $bFrom ?: '1970-01-01';

        return strcmp($fromA, $bTo ?: '9999-12-31') <= 0
            && strcmp($fromB, $aTo ?: '9999-12-31') <= 0;
    }

    private function zonesCompatible(?string $a, ?string $b): bool
    {
        return $a === null || $b === null || $a === $b;
    }

    private function placementContains(string $ancestorId, string $descendantId): bool
    {
        $current = Department::find($descendantId);
        $seen = [];

        while ($current !== null) {
            if ($current->id === $ancestorId) {
                return true;
            }
            if (in_array($current->id, $seen, true)) {
                break;
            }
            $seen[] = $current->id;
            $current = $current->parent;
        }

        return false;
    }
}

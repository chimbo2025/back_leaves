<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validated filter bag shared by every summary and drill-down report
 * endpoint (leave reports and payment reports alike).
 *
 * The whole point of this class is definitional unity: a summary total and
 * its detail table MUST be computed from the same filters, the same scope
 * and the same query builders. Controllers build one instance from the
 * request and hand it to both the summary service and the detail builders,
 * so the two can never drift apart (e.g. the frontend slicing by a different
 * date rule than the card total).
 *
 * Date overlap rule (matches the historical frontend semantics): a leave
 * row overlaps [from, to] unless it ends before `from` or starts after
 * `to`. A NULL end date means "open-ended" and always overlaps `from`.
 *
 * `leave_cycle` is an optional 4-digit year shorthand: it constrains the
 * overlap window to that calendar year (intersected with from/to when both
 * are present). There is no leave_cycles table; the cycle concept comes
 * from waivers/ledger rows (leave_cycle_start/end), and for reporting the
 * calendar year is the documented interpretation.
 */
class LeaveReportFilters
{
    public const MAX_PER_PAGE = 200;

    public const DEFAULT_PER_PAGE = 20;

    /** Sortable columns for leave-request rows. */
    public const REQUEST_SORTS = ['start_date', 'end_date', 'days', 'status', 'created_at', 'ref'];

    /** Sortable columns for employee rows. */
    public const STAFF_SORTS = ['full_name', 'department_id', 'zone_id', 'hire_date'];

    /** Sortable columns for payment-case rows. */
    public const PAYMENT_SORTS = ['created_at', 'status', 'calculated_amount', 'eligible_amount'];

    /** @var array<string,mixed> */
    protected array $filters;

    /**
     * @param  array<string,mixed>  $input  Raw query input.
     *
     * @throws ValidationException
     */
    public function __construct(array $input = [])
    {
        $data = $this->onlyKnown($input);

        Validator::make($data, [
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'leave_cycle' => ['nullable', 'regex:/^\d{4}$/'],
            'department_id' => 'nullable|string|exists:departments,id',
            'zone_id' => 'nullable|string|exists:zones,id',
            'employee_id' => 'nullable|string|exists:employees,id',
            'leave_type_id' => 'nullable|string|exists:leave_types,id',
            'leave_status' => 'nullable|string|in:draft,submitted,under_review,approved,returned_for_correction,returned,rejected,withdrawn,completed,paused_pending_handover',
            'payment_status' => 'nullable|string|in:pending,under_hr_review,hr_approved,hr_rejected,sent_to_accounting,processing,paid,payment_rejected,cancelled',
            'eligibility_status' => 'nullable|string|in:eligible,ineligible',
            'payment_type' => 'nullable|in:leave_encashment,leave_purchase',
            'gender' => 'nullable|in:M,F',
            'search' => 'nullable|string|max:100',
            'sort' => 'nullable|string|max:64',
            'direction' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1',
            // No max rule here: oversized per_page is clamped in perPage()
            // (matching the historical controller behaviour) rather than
            // rejected, so hand-written links degrade gracefully.
            'per_page' => 'nullable|integer|min:1',
        ])->validate();

        $this->filters = $data;
    }

    /** Keep only recognised keys so stray query params can never widen a report. */
    protected function onlyKnown(array $input): array
    {
        $known = [
            'from', 'to', 'leave_cycle', 'department_id', 'zone_id',
            'employee_id', 'leave_type_id', 'leave_status', 'payment_status',
            'eligibility_status', 'payment_type', 'gender', 'search',
            'sort', 'direction', 'page', 'per_page',
        ];

        $out = [];
        foreach ($known as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
                $out[$key] = $input[$key];
            }
        }

        return $out;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $default;
    }

    /** Echoed back in every meta block so callers can prove filter parity. */
    public function all(): array
    {
        return $this->filters;
    }

    /** Effective [from, to] window after intersecting leave_cycle. */
    public function window(): array
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;

        if (! empty($this->filters['leave_cycle'])) {
            $year = $this->filters['leave_cycle'];
            $from = $from === null ? "{$year}-01-01" : max($from, "{$year}-01-01");
            $to = $to === null ? "{$year}-12-31" : min($to, "{$year}-12-31");
        }

        return [$from, $to];
    }

    /**
     * Constrain a leave-date range to overlap the window.
     * NULL $toCol means open-ended and always overlaps `from`.
     */
    public function applyLeaveOverlap(Builder $query, string $fromCol = 'start_date', string $toCol = 'end_date'): Builder
    {
        [$from, $to] = $this->window();

        if ($from !== null) {
            $query->where(function (Builder $q) use ($from, $toCol) {
                $q->whereNull($toCol)->orWhere($toCol, '>=', $from);
            });
        }

        if ($to !== null) {
            $query->where($fromCol, '<=', $to);
        }

        return $query;
    }

    /** Constrain a created-at style timestamp to the window. */
    public function applyCreatedOverlap(Builder $query, string $column = 'created_at'): Builder
    {
        [$from, $to] = $this->window();

        if ($from !== null) {
            $query->whereDate($column, '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate($column, '<=', $to);
        }

        return $query;
    }

    /** Narrow by the identity filters (always narrowing, never widening). */
    public function applyIdentities(Builder $query, string $employeeCol = 'employee_id'): Builder
    {
        if (! empty($this->filters['employee_id'])) {
            $query->where($employeeCol, $this->filters['employee_id']);
        }

        if (! empty($this->filters['leave_type_id']) && $query->getModel()->getTable() !== 'leave_types') {
            $query->where('leave_type_id', $this->filters['leave_type_id']);
        }

        return $query;
    }

    /**
     * Resolve a requested sort against a whitelist. Unknown columns fall back
     * to the caller-supplied default instead of erroring, so hand-written
     * links can never inject an ORDER BY on an arbitrary column.
     *
     * @param  array<int,string>  $allowed
     * @return array{0:string,1:string}
     */
    public function sort(array $allowed, string $defaultColumn, string $defaultDirection = 'desc'): array
    {
        $column = $this->filters['sort'] ?? $defaultColumn;
        $direction = strtolower($this->filters['direction'] ?? $defaultDirection);

        if (! in_array($column, $allowed, true)) {
            $column = $defaultColumn;
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = $defaultDirection;
        }

        return [$column, $direction];
    }

    public function perPage(int $default = self::DEFAULT_PER_PAGE): int
    {
        return max(1, min((int) ($this->filters['per_page'] ?? $default), self::MAX_PER_PAGE));
    }
}

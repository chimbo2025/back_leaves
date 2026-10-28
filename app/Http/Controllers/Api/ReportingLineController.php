<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ReportingLine;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase 8 — Reporting-line CRUD improvements.
 *
 * Supervisor/reporting-line management is a separate admin action from
 * employee registration. These endpoints provide full lifecycle control:
 *   - list the chain for an employee
 *   - create a reporting line (with circular-reference guard)
 *   - update effective dates / relationship type
 *   - retire (soft-delete) a reporting line
 *   - find the current supervisor of a given employee
 *   - list all supervisees of a given employee
 *
 * "Reports to" is no longer a registration field (Phase 7); supervisors are
 * appointed after the fact through these dedicated endpoints.
 */
class ReportingLineController extends Controller
{
    public function __construct(
        protected AuditService $audit,
    ) {}

    /**
     * List reporting lines for an employee.
     * Shows direct supervisors; supports ?active=1 to filter current ones.
     */
    public function index(Request $request, Employee $employee): JsonResponse
    {
        $query = ReportingLine::where('employee_id', $employee->id)
            ->with(['supervisor:id,full_name,email,department_id,zone_id']);

        if ($request->boolean('active')) {
            $today = now()->toDateString();
            $query->where('effective_from', '<=', $today)
                ->where(function ($q) use ($today) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
                });
        }

        $lines = $query->orderBy('effective_from', 'desc')->get();

        return response()->json([
            'data' => $lines->map(fn (ReportingLine $line) => $this->payload($line)),
        ]);
    }

    /**
     * Create a reporting line for an employee.
     */
    public function store(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'supervisor_employee_id' => [
                'required', 'string',
                Rule::exists('employees', 'id'),
                function ($attribute, $value, $fail) use ($employee) {
                    if ($value === $employee->id) {
                        $fail('An employee cannot report to themselves.');
                    }
                    if (! Employee::where('id', $value)->where('is_active', true)->exists()) {
                        $fail('The selected supervisor is not an active employee.');
                    }
                },
            ],
            'relationship_type' => ['required', Rule::in(['primary', 'secondary', 'ad_hoc'])],
            'effective_from' => ['required', 'date', 'before_or_equal:effective_to'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        // Guard against circular references: walking up the chain must never
        // reach the employee they are being assigned as supervisor for.
        if ($this->createsCycle($employee->id, $data['supervisor_employee_id'], $data['effective_from'], $data['effective_to'] ?? null)) {
            return response()->json([
                'message' => 'This assignment would create a circular reporting chain.',
                'errors' => ['supervisor_employee_id' => ['Circular reference detected.']],
            ], 422);
        }

        // Only one ACTIVE primary relationship per employee at a time.
        if ($data['relationship_type'] === 'primary') {
            $today = now()->toDateString();
            $hasActivePrimary = ReportingLine::where('employee_id', $employee->id)
                ->where('relationship_type', 'primary')
                ->where('effective_from', '<=', $today)
                ->where(function ($q) use ($today) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
                })
                ->exists();

            if ($hasActivePrimary) {
                return response()->json([
                    'message' => 'Employee already has an active primary supervisor.',
                    'errors' => ['relationship_type' => ['Retire the existing primary line first.']],
                ], 422);
            }
        }

        DB::transaction(function () use ($employee, $data, $request) {
            $line = ReportingLine::create([
                'employee_id' => $employee->id,
                'supervisor_employee_id' => $data['supervisor_employee_id'],
                'relationship_type' => $data['relationship_type'],
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
            ]);

            $this->audit->append('reporting_line', (string) $line->id, 'create', $request->user()->id, [
                'employee_id' => $employee->id,
                'supervisor_employee_id' => $data['supervisor_employee_id'],
                'relationship_type' => $data['relationship_type'],
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
            ]);
        });

        $line = ReportingLine::where('employee_id', $employee->id)
            ->where('supervisor_employee_id', $data['supervisor_employee_id'])
            ->with('supervisor:id,full_name,email,department_id,zone_id')
            ->latest('id')
            ->first();

        return response()->json([
            'data' => $this->payload($line),
        ], 201);
    }

    /**
     * Update a reporting line (relationship type, effective dates).
     */
    public function update(Request $request, ReportingLine $line): JsonResponse
    {
        $data = $request->validate([
            'relationship_type' => ['sometimes', 'string', Rule::in(['primary', 'secondary', 'ad_hoc'])],
            'effective_from' => ['sometimes', 'date', 'before_or_equal:effective_to'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        // Prevent weakening the primary relationship check: if we are demoting
        // the current active primary, the new dates must not overlap another
        // active primary for the same employee.
        if (($data['relationship_type'] ?? '') !== 'primary') {
            $today = now()->toDateString();
            $from = $data['effective_from'] ?? $line->effective_from->toDateString();
            $to = $data['effective_to'] ?? null;
            $exists = ReportingLine::where('employee_id', $line->employee_id)
                ->where('id', '!=', $line->id)
                ->where('relationship_type', $line->relationship_type)
                ->where('effective_from', '<=', $from)
                ->where(function ($q) use ($today, $from, $to) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from);
                })
                ->exists();
            if ($exists) {
                return response()->json([
                    'message' => 'An overlapping reporting line exists for this employee.',
                    'errors' => ['effective_from' => ['Overlaps an existing reporting line.']],
                ], 422);
            }
        }

        $line->update($data);

        $this->audit->append('reporting_line', (string) $line->id, 'update', $request->user()->id, [
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
        ]);

        return response()->json([
            'data' => $this->payload($line->fresh()),
        ]);
    }

    /**
     * Retire a reporting line by setting effective_to to today.
     * This preserves the audit trail (archive over delete).
     */
    public function destroy(Request $request, ReportingLine $line): JsonResponse
    {
        $isCurrentlyActive = $line->effective_from->lte(now()->toDateString())
            && ($line->effective_to === null || $line->effective_to->gte(now()->toDateString()));

        if ($isCurrentlyActive && $line->relationship_type === 'primary') {
            $subordinates = ReportingLine::where('supervisor_employee_id', $line->employee_id)
                ->where('effective_from', '<=', now()->toDateString())
                ->where(function ($q) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString());
                })
                ->count();

            if ($subordinates > 0) {
                return response()->json([
                    'message' => sprintf(
                        'Cannot retire this primary supervisor: %d subordinate(s) currently report to them.',
                        $subordinates
                    ),
                    'errors' => ['employee_id' => ['Reassign subordinates first.']],
                ], 422);
            }
        }

        $line->update(['effective_to' => now()->toDateString()]);

        $this->audit->append('reporting_line', (string) $line->id, 'retire', $request->user()->id, [
            'employee_id' => $line->employee_id,
            'supervisor_employee_id' => $line->supervisor_employee_id,
        ]);

        return response()->json([
            'data' => ['id' => $line->id, 'retired' => true],
        ]);
    }

    /**
     * Current (active) direct supervisor for an employee.
     */
    public function supervisor(Request $request, Employee $employee): JsonResponse
    {
        $today = now()->toDateString();
        $line = ReportingLine::where('employee_id', $employee->id)
            ->where('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
            })
            ->with('supervisor:id,full_name,email,department_id,zone_id,position_id')
            ->orderByRaw("FIELD(relationship_type, 'primary', 'secondary', 'ad_hoc')")
            ->latest('effective_from')
            ->first();

        if (! $line) {
            return response()->json([
                'data' => null,
                'message' => 'No active reporting line found.',
            ]);
        }

        return response()->json([
            'data' => $this->payload($line),
        ]);
    }

    /**
     * All employees who currently report (directly) to the given supervisor.
     */
    public function supervisees(Request $request, Employee $supervisor): JsonResponse
    {
        $today = now()->toDateString();
        $query = ReportingLine::where('supervisor_employee_id', $supervisor->id)
            ->where('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
            })
            ->with('employee:id,full_name,email,department_id,zone_id,position_id,is_active');

        $lines = $query->orderBy('relationship_type')->get();

        return response()->json([
            'data' => $lines->map(fn (ReportingLine $line) => $this->payload($line)),
        ]);
    }

    /**
     * Organization-wide reporting-line list (admin view).
     * Supports ?active=1 and ?department_id= scope.
     */
    public function all(Request $request): JsonResponse
    {
        $query = ReportingLine::with(['employee:id,full_name,department_id,zone_id', 'supervisor:id,full_name,department_id,zone_id']);

        if ($request->boolean('active')) {
            $today = now()->toDateString();
            $query->where('effective_from', '<=', $today)
                ->where(function ($q) use ($today) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
                });
        }

        if ($request->filled('department_id')) {
            $query->where('employee_id', function ($q) use ($request) {
                $q->select('id')->from('employees')->where('department_id', $request->input('department_id'));
            });
        }

        $lines = $query->orderBy('employee_id')->get();

        return response()->json([
            'data' => $lines->map(fn (ReportingLine $line) => $this->payload($line)),
        ]);
    }

    /**
     * Check whether adding a reporting line employee → supervisor (effective
     * [from, to]) would create a cycle somewhere up the chain.
     */
    protected function createsCycle(string $employeeId, string $supervisorId, ?string $from, ?string $to): bool
    {
        $visited = [];
        $current = $supervisorId;
        $attempts = 0;

        while ($current !== null && $current !== $employeeId && $attempts < 100) {
            if (in_array($current, $visited, true)) {
                return true; // cycle in existing data
            }
            $visited[] = $current;
            $attempts++;

            $current = ReportingLine::where('employee_id', $current)
                ->where('effective_from', '<=', $from ?? now()->toDateString())
                ->where(function ($q) use ($from, $to) {
                    $q->whereNull('effective_to')
                        ->orWhere(function ($sub) use ($to) {
                            $sub->whereNotNull('effective_to')
                                ->where('effective_to', '>=', $to ?? '1970-01-01');
                        });
                })
                ->value('supervisor_employee_id');
        }

        return $current === $employeeId;
    }

    /**
     * Serialise a ReportingLine into the API payload.
     */
    protected function payload(ReportingLine $line): array
    {
        $supervisor = $line->supervisor;

        return [
            'id' => $line->id,
            'employee_id' => $line->employee_id,
            'supervisor_employee_id' => $line->supervisor_employee_id,
            'supervisor' => $supervisor
                ? [
                    'id' => $supervisor->id,
                    'full_name' => $supervisor->full_name,
                    'email' => $supervisor->email,
                    'department_id' => $supervisor->department_id,
                    'zone_id' => $supervisor->zone_id,
                ]
                : null,
            'relationship_type' => $line->relationship_type,
            'effective_from' => $line->effective_from?->toDateString(),
            'effective_to' => $line->effective_to?->toDateString(),
            'is_active' => $line->effective_from?->lte(now()->toDateString())
                && ($line->effective_to === null || $line->effective_to?->gte(now()->toDateString())),
        ];
    }
}

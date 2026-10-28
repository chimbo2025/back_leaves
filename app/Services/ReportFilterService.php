<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Report filter service (Phase 7).
 *
 * Normalises, validates, and merges incoming request filters into a canonical
 * array that the ReportQueryService understands. Also provides the list of
 * available filter fields per report type for the frontend catalogue.
 */
class ReportFilterService
{
    /**
     * Canonical filter fields per report type.
     *
     * @return array<string,array<int,string>>
     */
    public function availableFilters(): array
    {
        return [
            'leave_requests' => [
                'date_from', 'date_to', 'employee_id', 'department_id',
                'zone_id', 'leave_type_id', 'status', 'policy_version',
            ],
            'employee_history' => [
                'date_from', 'date_to', 'employee_id', 'department_id',
                'zone_id', 'leave_type_id', 'status', 'policy_version',
            ],
            'leave_plans' => [
                'date_from', 'date_to', 'employee_id', 'department_id',
                'zone_id', 'status',
            ],
            'waivers' => [
                'date_from', 'date_to', 'employee_id', 'status',
                'requested_action',
            ],
            'returns' => [
                'date_from', 'date_to', 'employee_id', 'status',
            ],
            'balance' => [
                'date_from', 'date_to', 'employee_id', 'department_id',
                'zone_id', 'leave_type_id',
            ],
            'payments' => [
                'date_from', 'date_to', 'employee_id', 'department_id',
                'zone_id', 'status', 'payment_type',
            ],
            'policies' => [
                'date_from', 'date_to', 'leave_type_id', 'status',
            ],
            'approval_performance' => [
                'date_from', 'date_to', 'employee_id', 'department_id',
                'zone_id', 'decision', 'role',
            ],
            'documents' => [
                'date_from', 'date_to', 'employee_id', 'document_type', 'status',
            ],
            'audit' => [
                'date_from', 'date_to', 'entity_type', 'entity_id',
                'action', 'actor_user_id',
            ],
        ];
    }

    /**
     * Normalise and validate filters from a request.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function normalise(string $reportType, array $input): array
    {
        $available = $this->availableFilters()[$reportType] ?? [];
        $filters = [];

        foreach ($available as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '') {
                $filters[$field] = $input[$field];
            }
        }

        // Type coercion for known fields
        if (isset($filters['date_from'])) {
            $filters['date_from'] = $this->parseDate($filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $filters['date_to'] = $this->parseDate($filters['date_to']);
        }

        return $filters;
    }

    /**
     * Validate filters for a report type.
     *
     * @param  array<string,mixed>  $filters
     *
     * @throws ValidationException
     */
    public function validate(string $reportType, array $filters): void
    {
        $rules = $this->validationRules($reportType);

        if ($rules !== []) {
            Validator::make($filters, $rules)
                ->validate();
        }
    }

    /**
     * Validation rules per report type.
     *
     * @return array<string,array<string,string>>
     */
    protected function validationRules(string $reportType): array
    {
        return match ($reportType) {
            'leave_requests', 'employee_history', 'waivers', 'returns', 'balance', 'payments', 'policies', 'approval_performance', 'documents', 'audit' => [
                'date_from' => 'nullable|date_format:Y-m-d',
                'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
                'employee_id' => 'nullable|string|exists:employees,id',
                'department_id' => 'nullable|string|exists:departments,id',
                'zone_id' => 'nullable|string|exists:zones,id',
                'leave_type_id' => 'nullable|string|exists:leave_types,id',
                'status' => 'nullable|string',
                'policy_version' => 'nullable|string',
                'requested_action' => 'nullable|string',
                'payment_type' => 'nullable|in:leave_encashment,leave_purchase',
                'decision' => 'nullable|in:approved,rejected,returned',
                'role' => 'nullable|string|exists:roles,id',
                'document_type' => 'nullable|in:approval_letter,rejection_letter,cancellation_letter',
                'entity_type' => 'nullable|string',
                'entity_id' => 'nullable|string',
                'action' => 'nullable|string',
                'actor_user_id' => 'nullable|string|exists:users,id',
            ],
            'leave_plans' => [
                'date_from' => 'nullable|date_format:Y-m-d',
                'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
                'employee_id' => 'nullable|string|exists:employees,id',
                'department_id' => 'nullable|string|exists:departments,id',
                'zone_id' => 'nullable|string|exists:zones,id',
                'status' => 'nullable|string',
            ],
            default => [],
        };
    }

    protected function parseDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }

    /**
     * Get default date range (current year to +3 months).
     */
    public function defaultRange(): array
    {
        return [
            'date_from' => now()->startOfYear()->toDateString(),
            'date_to' => now()->addMonths(3)->endOfMonth()->toDateString(),
        ];
    }

    /**
     * Extract filters from a Request object.
     */
    public function fromRequest(string $reportType, Request $request): array
    {
        $input = $request->only($this->availableFilters()[$reportType] ?? []);

        return $this->normalise($reportType, $input);
    }
}

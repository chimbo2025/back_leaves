<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add missing indexes for query performance (Phase 7 stabilization).
 *
 * These indexes support common query patterns:
 * - leave_requests: filtered by status, employee_id, date ranges, current_owner_id
 * - workflow_assignments: filtered by request_id, assigned_employee_id, status, due_at
 * - approval_actions: filtered by assignment_id, actor_employee_id, acted_at
 * - leave_returns: filtered by leave_request_id, employee_id, status, expected_return_date
 * - leave_payment_cases: filtered by employee_id, status, leave_type_id
 * - official_leave_letters: filtered by leave_request_id, qr_token, revoked_at
 * - report_exports: filtered by requested_by, status, expires_at
 * - approval_signatures: filtered by leave_request_id, actor_id, approval_action_id
 */
return new class extends Migration
{
    /**
     * Add a plain index only when no index with the same auto-generated name
     * exists yet, so a resumed or hand-patched run converges on 1061 instead
     * of failing with "Duplicate key name".
     *
     * @param  array<int,string>|string  $columns
     */
    private function addIndexIfMissing(string $table, array|string $columns): void
    {
        $columns = (array) $columns;
        $name = strtolower($table.'_'.implode('_', $columns).'_index');

        $existing = collect(Schema::getIndexes($table))->pluck('name')->all();
        if (in_array($name, $existing, true)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($columns) {
            $t->index($columns);
        });
    }

    public function up(): void
    {
        // leave_requests - most queried table
        foreach (['status', 'employee_id', 'start_date', 'end_date', 'current_owner_id'] as $column) {
            $this->addIndexIfMissing('leave_requests', $column);
        }
        $this->addIndexIfMissing('leave_requests', ['status', 'employee_id']);
        $this->addIndexIfMissing('leave_requests', ['status', 'start_date', 'end_date']);

        // workflow_assignments - heavy queries for inbox and chain resolution
        foreach (['request_id', 'assigned_employee_id', 'status', 'due_at'] as $column) {
            $this->addIndexIfMissing('workflow_assignments', $column);
        }
        $this->addIndexIfMissing('workflow_assignments', ['assigned_employee_id', 'status']);
        $this->addIndexIfMissing('workflow_assignments', ['request_id', 'status']);

        // approval_actions - audit trail queries
        foreach (['assignment_id', 'actor_employee_id', 'acted_at'] as $column) {
            $this->addIndexIfMissing('approval_actions', $column);
        }
        $this->addIndexIfMissing('approval_actions', ['assignment_id', 'acted_at']);

        // leave_returns - Phase 3 return-to-work
        if (Schema::hasTable('leave_returns')) {
            foreach (['leave_request_id', 'employee_id', 'status', 'expected_return_date'] as $column) {
                $this->addIndexIfMissing('leave_returns', $column);
            }
            // ['employee_id', 'status'] already exists in 2026_10_11_090200_create_leave_returns_table.php
        }

        // leave_payment_cases - Phase 5 payments
        if (Schema::hasTable('leave_payment_cases')) {
            // ['employee_id', 'status'] already exists in 2026_10_11_110000_create_leave_payment_cases_table.php
            $this->addIndexIfMissing('leave_payment_cases', 'leave_type_id');
            $this->addIndexIfMissing('leave_payment_cases', ['leave_request_id', 'payment_type']);
        }

        // official_leave_letters - Phase 6 documents
        if (Schema::hasTable('official_leave_letters')) {
            $this->addIndexIfMissing('official_leave_letters', 'leave_request_id');
            // qr_token (unique + index) and revoked_at already exist in 2026_10_11_120100_create_official_leave_letters_table.php
            $this->addIndexIfMissing('official_leave_letters', ['leave_request_id', 'document_type']);
        }

        // report_exports - Phase 7 exports
        // ['requested_by', 'status'], ['expires_at'] and ['report_type', 'created_at']
        // already exist in 2026_10_11_130000_create_report_exports_table.php.
        // No new indexes needed for this table.

        // approval_signatures - Phase 6 digital signatures
        if (Schema::hasTable('approval_signatures')) {
            $this->addIndexIfMissing('approval_signatures', 'leave_request_id');
            // approval_action_id already exists in 2026_10_11_120000_create_approval_signatures_table.php
            $this->addIndexIfMissing('approval_signatures', 'actor_id');
            $this->addIndexIfMissing('approval_signatures', ['leave_request_id', 'approval_action']);
        }
    }

    public function down(): void
    {
        // leave_requests
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['employee_id']);
            $table->dropIndex(['start_date']);
            $table->dropIndex(['end_date']);
            $table->dropIndex(['current_owner_id']);
            $table->dropIndex(['status', 'employee_id']);
            $table->dropIndex(['status', 'start_date', 'end_date']);
        });

        // workflow_assignments
        Schema::table('workflow_assignments', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropIndex(['assigned_employee_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['due_at']);
            $table->dropIndex(['assigned_employee_id', 'status']);
            $table->dropIndex(['request_id', 'status']);
        });

        // approval_actions
        Schema::table('approval_actions', function (Blueprint $table) {
            $table->dropIndex(['assignment_id']);
            $table->dropIndex(['actor_employee_id']);
            $table->dropIndex(['acted_at']);
            $table->dropIndex(['assignment_id', 'acted_at']);
        });

        // leave_returns
        if (Schema::hasTable('leave_returns')) {
            Schema::table('leave_returns', function (Blueprint $table) {
                $table->dropIndex(['leave_request_id']);
                $table->dropIndex(['employee_id']);
                $table->dropIndex(['status']);
                $table->dropIndex(['expected_return_date']);
                $table->dropIndex(['employee_id', 'status']);
            });
        }

        // leave_payment_cases
        if (Schema::hasTable('leave_payment_cases')) {
            Schema::table('leave_payment_cases', function (Blueprint $table) {
                $table->dropIndex(['employee_id', 'status']);
                $table->dropIndex(['leave_type_id']);
                $table->dropIndex(['leave_request_id', 'payment_type']);
            });
        }

        // official_leave_letters
        if (Schema::hasTable('official_leave_letters')) {
            Schema::table('official_leave_letters', function (Blueprint $table) {
                $table->dropIndex(['leave_request_id']);
                $table->dropIndex(['qr_token']);
                $table->dropIndex(['revoked_at']);
                $table->dropIndex(['leave_request_id', 'document_type']);
            });
        }

        // report_exports
        if (Schema::hasTable('report_exports')) {
            Schema::table('report_exports', function (Blueprint $table) {
                $table->dropIndex(['requested_by', 'status']);
                $table->dropIndex(['expires_at']);
                $table->dropIndex(['report_type', 'created_at']);
            });
        }

        // approval_signatures
        if (Schema::hasTable('approval_signatures')) {
            Schema::table('approval_signatures', function (Blueprint $table) {
                $table->dropIndex(['leave_request_id']);
                $table->dropIndex(['approval_action_id']);
                $table->dropIndex(['actor_id']);
                $table->dropIndex(['leave_request_id', 'approval_action']);
            });
        }
    }
};

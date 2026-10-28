<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report exports (Phase 7).
 *
 * Tracks every report export request with its filters, scope, and result.
 * Exports are processed asynchronously via queue; the status tracks progress.
 * The file is stored on the private 'local' disk; downloads use temporary
 * signed URLs that expire. Expired or cancelled exports cannot be downloaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ensure the modules table has the required rows for the catalog integrity test
        DB::table('modules')->updateOrInsert(
            ['id' => 'report_exports'],
            ['label' => 'Report Exports', 'description' => 'Report export management and history.', 'is_active' => true]
        );
        DB::table('modules')->updateOrInsert(
            ['id' => 'audit_logs'],
            ['label' => 'Audit Logs', 'description' => 'Audit log search and chain verification.', 'is_active' => true]
        );

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();

            // What kind of report this is.
            $table->string('report_type'); // e.g. 'leave_requests', 'employee_history', 'balance', etc.

            // Who asked for it.
            $table->string('requested_by'); // employee_id

            // The filters the user selected.
            $table->json('filters_json');

            // The scope the user had at generation time (snapshot).
            $table->json('scope_snapshot_json');

            // Output format.
            $table->enum('format', ['csv', 'pdf']);

            // Processing status.
            $table->enum('status', [
                'queued',
                'processing',
                'completed',
                'failed',
                'expired',
                'cancelled',
            ])->default('queued');

            // File storage (private disk).
            $table->string('file_path')->nullable();
            $table->string('file_disk', 20)->default('local');

            // Integrity & metadata.
            $table->string('file_hash', 64)->nullable(); // SHA-256 of file content
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedInteger('column_count')->nullable();

            // Timing.
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // auto-expiry
            $table->timestamp('cancelled_at')->nullable();

            // Failure info.
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['requested_by', 'status']);
            $table->index('status');
            $table->index('expires_at');
            $table->index(['report_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};

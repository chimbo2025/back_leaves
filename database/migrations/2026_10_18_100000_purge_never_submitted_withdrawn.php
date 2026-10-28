<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * One-off cleanup of "deleted" drafts. Before true draft deletion
     * existed, the UI's delete action only withdrew a request — marking it
     * withdrawn while the row stayed visible in every list forever.
     *
     * The identification is exact: withdrawn AND never submitted
     * (submitted_at IS NULL). Every real submission stamps submitted_at
     * (LeaveRequestService::submit), so requests that genuinely entered
     * approval and were later withdrawn remain untouched as history.
     */
    public function up(): void
    {
        DB::transaction(function () {
            $ids = DB::table('leave_requests')
                ->where('status', 'withdrawn')
                ->whereNull('submitted_at')
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            foreach (DB::table('leave_documents')->whereIn('request_id', $ids)->pluck('file_ref') as $fileRef) {
                if ($fileRef) {
                    Storage::disk('local')->delete($fileRef);
                }
            }

            // Child tables cascade at the DB level (leave_request_dates,
            // leave_documents); the explicit deletes keep this migration
            // total even if a foreign key ever changes. Drafts never had
            // workflow assignments — the delete is defensive.
            DB::table('workflow_assignments')->whereIn('request_id', $ids)->delete();
            DB::table('leave_request_dates')->whereIn('request_id', $ids)->delete();
            DB::table('leave_documents')->whereIn('request_id', $ids)->delete();
            DB::table('leave_requests')->whereIn('id', $ids)->delete();
        });
    }

    public function down(): void
    {
        // Irreversible by design — the junk rows are gone for good.
    }
};

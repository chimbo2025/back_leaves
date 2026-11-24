<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee request letter (application). Separate from the HR response letter
 * (letter_body) and from the free-form reason/explanation. The employee
 * reviews and edits this text before submitting; approvers read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_requests') && ! Schema::hasColumn('leave_requests', 'request_body')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->text('request_body')->nullable()->after('reason');
            });
        }
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('request_body');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->json('policy_snapshot')->nullable()->after('policy_id');
            $table->json('required_docs_provided')->nullable()->after('policy_snapshot');
            $table->boolean('medical_required')->nullable()->after('required_docs_provided');
            $table->timestamp('eligibility_checked_at')->nullable()->after('medical_required');
            $table->json('eligibility_blockers')->nullable()->after('eligibility_checked_at');
            $table->boolean('eligibility_ok')->nullable()->after('eligibility_blockers');
            $table->string('policy_version')->nullable()->after('eligibility_ok');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['policy_snapshot', 'required_docs_provided', 'medical_required', 'eligibility_checked_at', 'eligibility_blockers', 'eligibility_ok', 'policy_version']);
        });
    }
};

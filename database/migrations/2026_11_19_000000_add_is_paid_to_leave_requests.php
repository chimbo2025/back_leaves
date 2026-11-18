<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_requests') && ! Schema::hasColumn('leave_requests', 'is_paid')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->boolean('is_paid')->nullable()->after('days');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_requests') && Schema::hasColumn('leave_requests', 'is_paid')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->dropColumn('is_paid');
            });
        }
    }
};
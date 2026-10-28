<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Return date is decided by the approver at approval time, so end_date
 * (and the derived days count) stay null until the request is decided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->date('end_date')->nullable()->change();
            $table->unsignedInteger('days')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->date('end_date')->nullable(false)->change();
            $table->unsignedInteger('days')->nullable(false)->change();
        });
    }
};

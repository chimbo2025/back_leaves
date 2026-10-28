<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workflow definitions become activatable/deactivatable. Deactivation is
     * the soft alternative to deletion: the resolver only ever considers
     * active definitions, so a deactivated chain stops routing new
     * submissions the moment it is switched off — while definitions carrying
     * assignment history stay on file.
     */
    public function up(): void
    {
        Schema::table('workflow_definitions', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('workflow_definitions', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};

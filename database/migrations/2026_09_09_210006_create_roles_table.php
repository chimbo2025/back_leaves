<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint (2): roles are FUNCTIONAL. approval_tier + is_final_authority
     * live here, not on positions. Built-in ids: SUPERVISOR, DEPT_HEAD,
     * ZONAL_HEAD, HR, FINAL_AUTHORITY; administration can add custom roles.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->string('id')->primary(); // 'HR', 'ZONAL_HEAD', ... (custom roles allowed)
            $table->string('name');
            $table->unsignedTinyInteger('approval_tier')->nullable();
            $table->boolean('is_final_authority')->default(false);
            $table->boolean('is_system')->default(false); // protect seeded roles from deletion
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};

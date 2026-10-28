<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delegations', function (Blueprint $table) {
            $table->string('approval_role_id')->nullable();
            $table->foreign('approval_role_id')->references('id')->on('approval_roles')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('delegations', function (Blueprint $table) {
            $table->dropForeign(['approval_role_id']);
            $table->dropColumn('approval_role_id');
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_default_roles', function (Blueprint $table) {
            $table->string('position_id');
            $table->string('role_id');
            $table->primary(['position_id', 'role_id']);

            $table->foreign('position_id')->references('id')->on('positions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_default_roles');
    }
};

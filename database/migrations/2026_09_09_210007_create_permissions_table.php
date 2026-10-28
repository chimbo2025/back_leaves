<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // 'requests.approve', 'approve_zone', ...
            $table->string('module')->default('requests'); // requests|policies|documents|delegations|reports|admin
            $table->string('action')->default('view');     // view|create|update|delete|approve|return|reject|export
            $table->string('label')->nullable();
            $table->string('hint')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};

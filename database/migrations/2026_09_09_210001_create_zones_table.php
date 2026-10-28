<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->string('id')->primary(); // z1..z4 (frontend parity)
            $table->string('name');
            $table->string('short')->nullable();
            $table->boolean('is_hq')->default(false);
            $table->boolean('is_pemba')->default(false);
            $table->boolean('is_coordination')->default(false);
            $table->string('parent_zone_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};

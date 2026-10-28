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
        Schema::create('leave_rules', function (Blueprint $table) {
            $table->id();
            $table->string('leave_policy_id')->index();
            $table->string('rule_key');
            $table->string('operator');
            $table->json('value_json')->nullable();
            $table->json('condition_json')->nullable();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->boolean('is_inherited')->default(false);
            $table->unsignedBigInteger('inherited_from_rule_id')->nullable()->index();
            $table->boolean('overridden')->default(false);
            $table->string('source_reference')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_rules');
    }
};

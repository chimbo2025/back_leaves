<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint §1.5 — tamper-evident, hash-chained ledger. row_hash computed
     * in the application layer (use AuditService::append) so the chain is
     * unit-testable: sha256(prev_hash || canonicalPayload || created_at).
     * Any retroactive edit breaks the chain and verify() detects it.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('action'); // create|update|decision|correction|version|handover|approve|reject|return
            $table->string('actor_user_id')->nullable();
            $table->json('payload');
            $table->text('reason')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('row_hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

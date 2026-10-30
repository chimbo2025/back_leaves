<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow refactor — dynamic approval-role step configuration.
 *
 * Approval roles already live in `approval_roles` and step→role linkage in
 * `approval_role_id`. These three columns complete the configuration surface:
 *   - allow_same_person_as_previous_steps: opt-in duplicate-approver collapse
 *     override (default false: the same approver is collapsed once);
 *   - fallback_approval_role_id: role to try when the primary role has no
 *     standing holder;
 *   - conditions: JSON predicate bag evaluated by the resolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('workflow_steps', 'allow_same_person_as_previous_steps')) {
                $table->boolean('allow_same_person_as_previous_steps')->default(false);
            }
            if (! Schema::hasColumn('workflow_steps', 'fallback_approval_role_id')) {
                $table->string('fallback_approval_role_id')->nullable();
            }
            if (! Schema::hasColumn('workflow_steps', 'conditions')) {
                $table->json('conditions')->nullable();
            }
        });

        // Dynamic catalog steps reference approval_role_id only; the legacy
        // role_required pointer must therefore be optional.
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->string('role_required')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            foreach (['allow_same_person_as_previous_steps', 'fallback_approval_role_id', 'conditions'] as $column) {
                if (Schema::hasColumn('workflow_steps', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

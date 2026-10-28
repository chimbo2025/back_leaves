<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permission and role lifecycle (audit §6.2).
 *
 * Until this migration every row existed or did not. A permission was in the
 * catalog and therefore renderable as an RBAC matrix switch, and a role was in
 * the catalog and therefore offered a Delete button that the server then refused
 * for system roles. Neither could express "we intend to add this", "this is no
 * longer offered", or "this is retired but its history must remain readable".
 *
 * This migration adds that vocabulary. It is deliberately additive:
 *
 *   modules                 the 7 modules the catalog is organised by, so a
 *                           module is a real row rather than a free-text string
 *                           on permissions.module
 *   permissions.status      planned | implemented | deprecated
 *   permissions.active      may this code be granted to a role at all?
 *   permissions.enforcement_reference
 *                           where the code is enforced, so "implemented" is a
 *                           checkable claim and not a label
 *   roles.active            false = archived. Grants stop applying immediately;
 *                           the row survives so historic decisions and audit
 *                           entries still resolve to a name
 *   employee_roles.active   false = the individual grant was withdrawn without
 *                           deleting the row
 *
 * status and active overlap on purpose: active is the operational switch
 * (cheap to flip, honoured on every read), status is editorial (planned means
 * "not enforced yet"). A planned code is active=false by construction, so there
 * is no state in which a code can be granted but is not enforced.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            Schema::create('modules', function ($table) {
                $table->string('id', 64)->primary();
                $table->string('label');
                $table->string('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        Schema::table('permissions', function ($table) {
            $table->string('status', 16)->default('implemented')->after('hint');
            $table->boolean('active')->default(true)->after('status');
            $table->string('enforcement_reference')->nullable()->after('active');
            $table->index(['status', 'active']);
        });

        Schema::table('roles', function ($table) {
            $table->boolean('active')->default(true)->after('is_system');
        });

        Schema::table('employee_roles', function ($table) {
            $table->boolean('active')->default(true)->after('effective_to');
        });

        $labels = [
            'requests' => ['Leave Requests', 'Submitting, approving and acting on leave requests.'],
            'policies' => ['Leave Policies', 'Policy authoring and the rules a request is measured against.'],
            'documents' => ['Documents', 'Supporting and medical documentation attached to a request.'],
            'admin' => ['Administration', 'Employees, roles, departments, zones and reference data.'],
            'delegations' => ['Delegations', 'Acting cover granted to a colleague for a bounded window.'],
            'reports' => ['Reports', 'Aggregate and per-zone reporting surfaces.'],
            'staff' => ['Staff', 'Staff directory visibility.'],
        ];

        foreach (array_keys($labels) as $module) {
            DB::table('modules')->updateOrInsert(
                ['id' => $module],
                ['label' => $labels[$module][0], 'description' => $labels[$module][1], 'is_active' => true]
            );
        }

        DB::table('permissions')->update([
            'status' => 'implemented',
            'active' => true,
        ]);

        // Record where each catalogued code is enforced, so "implemented" is a
        // checkable claim rather than a label. The map itself lives in
        // config/permission_enforcement.php because the integrity test scans
        // app/ and routes/ for quoted "module.action" literals to decide what is
        // wired: a map kept in app/ would make every code look enforced and
        // quietly disable the guard.
        //
        // Only 6 of the 23 codes exist at this point. The remaining 17 are
        // created by DatabaseSeeder, which calls the same applyLifecycleDefaults
        // so neither ordering leaves a code without provenance.
        if (class_exists(Permission::class)) {
            Permission::applyLifecycleDefaults();
        } else {
            foreach (config('permission_enforcement.references', []) as $code => $reference) {
                DB::table('permissions')->where('code', $code)->update([
                    'enforcement_reference' => $reference,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('employee_roles', function ($table) {
            $table->dropColumn('active');
        });

        Schema::table('roles', function ($table) {
            $table->dropColumn('active');
        });

        Schema::table('permissions', function ($table) {
            $table->dropIndex(['status', 'active']);
            $table->dropColumn(['status', 'active', 'enforcement_reference']);
        });

        Schema::dropIfExists('modules');
    }
};

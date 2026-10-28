<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give roles a human-readable description.
     *
     * The settings UI already collects a "scope description" per role, but it had
     * nowhere to live, so it was discarded on every reload and every admin saw a
     * blank explanation of what a role is for. Nullable: seeded/system roles
     * simply have none, and that must stay valid.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('name');
        });

        // Backfill roles that already exist (this migration runs before the
        // seeder on a fresh install, where the table is still empty and the
        // seeder supplies the same text). Keep these in step with seedRoles().
        $descriptions = [
            'SUPERVISOR' => 'Line manager for day-to-day leave approval within their own reporting line.',
            'DEPT_HEAD' => 'Heads one department. Scope comes from the department grant; can approve, return and reject without any zone reach.',
            'ZONAL_HEAD' => 'Heads one zone. Scope comes from the zone grant; can approve within that zone only.',
            'HR' => 'Central HR. Cross-zone read for requests, staff and reports; administers staff, roles and leave policy.',
            'FINAL_AUTHORITY' => 'Final sign-off. Wildcard authority across the whole organisation.',
        ];

        foreach ($descriptions as $id => $description) {
            DB::table('roles')->where('id', $id)->whereNull('description')->update([
                'description' => $description,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};

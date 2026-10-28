<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['code', 'module', 'action', 'label', 'hint', 'status', 'active', 'enforcement_reference'];

    public function isGrantable(): bool
    {
        return $this->active && $this->status === 'implemented';
    }

    /**
     * Row shape for the matrix payload. Built explicitly rather than through
     * $appends: `is_grantable` collides with Laravel's legacy
     * getIsGrantableAttribute() lookup for a `is_` prefixed attribute, which
     * forwards to __call and fatals. The flag is added here instead so the UI
     * reads grantability from the database rather than re-deriving
     * `status === 'implemented'` in TypeScript, where the two could drift.
     */
    public function toMatrixArray(): array
    {
        return array_merge($this->toArray(), ['is_grantable' => $this->isGrantable()]);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * A code that is not enforced cannot be granted. "planned" codes are active
     * but inactive-by-construction, so the matrix can list them while no role
     * can hold them.
     */

    /**
     * Where each catalogued code is actually enforced. See
     * config/permission_enforcement.php for why this is not defined inline.
     *
     * @return array<string,string>
     */
    public static function enforcementReferences(): array
    {
        return config('permission_enforcement.references', []);
    }

    /**
     * Codes that gate a frontend surface but no backend check. They are
     * grantable on purpose: the API relies on workflow assignment ownership
     * instead, so they widen the UI without widening the API.
     *
     * @return array<int,string>
     */
    public static function frontendOnlyCodes(): array
    {
        return config('permission_enforcement.frontend_only', []);
    }

    /**
     * Fill in the lifecycle columns for catalog rows that do not have them yet.
     *
     * This has to be callable from the seeder as well as the migration, because
     * migrations alone only create 6 of the 23 codes: the other 17 arrive from
     * DatabaseSeeder::seedPermissions() via firstOrCreate. Applying lifecycle
     * metadata only at migration time therefore marked 17 codes implemented
     * with a null enforcement_reference.
     *
     * A code with no known enforcement site is recorded as planned and not
     * grantable rather than labelled implemented on faith, except for the
     * declared frontend-only capabilities, which are enforced by the SPA.
     */
    public static function applyLifecycleDefaults(): void
    {
        $references = self::enforcementReferences();
        $frontendOnly = self::frontendOnlyCodes();

        foreach (self::query()->get() as $permission) {
            $reference = $permission->enforcement_reference
                ?? $references[$permission->code]
                ?? null;

            $enforced = $reference !== null || in_array($permission->code, $frontendOnly, true);

            $permission->forceFill([
                'status' => $enforced ? ($permission->status ?: 'implemented') : 'planned',
                'active' => $enforced ? ($permission->active ?? true) : false,
                'enforcement_reference' => $reference,
            ])->save();
        }
    }
}

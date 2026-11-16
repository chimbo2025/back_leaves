<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Employee extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'user_id', 'payroll_no', 'full_name',
        'first_name', 'middle_name', 'last_name',
        'initials', 'profile_photo_path',
        'position_id', 'department_id',
        'division_id', 'unit_id', 'zone_id', 'gender', 'email', 'hire_date', 'is_active',
    ];

    /**
     * Every serialised employee carries its avatar payload, so list, detail,
     * dropdown and nested (approval/request/notification) responses agree.
     *
     * @var array<int, string>
     */
    protected $appends = ['avatar_initials', 'avatar_url'];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Keep `full_name`, its parts and `initials` consistent wherever an
     * employee is written (registration, profile patch, seeder).
     *
     * Rules:
     *   - parts without a name are composed into full_name;
     *   - a supplied/changed full_name is split into first/middle/last;
     *   - initials are derived only when the caller did NOT supply them, so a
     *     deliberate HR value ("AY") is never overwritten.
     */
    protected static function booted(): void
    {
        static::saving(function (self $employee) {
            $full = trim((string) $employee->full_name);
            $partsDirty = $employee->isDirty('first_name')
                || $employee->isDirty('middle_name')
                || $employee->isDirty('last_name');

            if ($full === '' && $partsDirty) {
                $full = self::composeFullName(
                    $employee->first_name,
                    $employee->middle_name,
                    $employee->last_name,
                );
                $employee->full_name = $full;
            }

            if ($full !== '' && $employee->isDirty('full_name')) {
                [$first, $middle, $last] = self::splitFullName($full);

                // A caller that posts parts wins for those parts; anything it
                // left blank is filled from the display name so the row is
                // never half-populated.
                if (! $partsDirty) {
                    $employee->first_name = $first === '' ? null : $first;
                    $employee->middle_name = $middle === '' ? null : $middle;
                    $employee->last_name = $last === '' ? null : $last;
                } else {
                    if (self::cleanNamePart($employee->first_name) === '') {
                        $employee->first_name = $first === '' ? null : $first;
                    }
                    if (self::cleanNamePart($employee->middle_name) === '') {
                        $employee->middle_name = $middle === '' ? null : $middle;
                    }
                    if (self::cleanNamePart($employee->last_name) === '') {
                        $employee->last_name = $last === '' ? null : $last;
                    }
                }
            }

            $nameChanged = $employee->isDirty('full_name')
                || $employee->isDirty('first_name')
                || $employee->isDirty('middle_name')
                || $employee->isDirty('last_name');

            // An explicit, non-empty initials value is HR's choice and stays.
            // A dirty-but-empty one (or none at all) means "derive it".
            $initialsSupplied = $employee->isDirty('initials')
                && self::cleanNamePart($employee->initials) !== '';

            if ($nameChanged && ! $initialsSupplied) {
                $employee->initials = self::initialsFor(
                    $employee->first_name,
                    $employee->last_name,
                    $employee->full_name,
                );
            }
        });
    }

    /**
     * Display initials: first letter of the first name + first letter of the
     * last name, uppercase, at most two characters.
     *
     *   Haji Ali Said   => HS
     *   Asha Juma Hamisi => AH
     *   Haji (no last)  => HA
     *   Said (no first) => SA
     *   no name at all  => --
     */
    public function getAvatarInitialsAttribute(): string
    {
        return self::initialsFor($this->first_name, $this->last_name, $this->full_name);
    }

    /** Public URL for a stored profile photo, or NULL when there is none. */
    public function getAvatarUrlAttribute(): ?string
    {
        $path = trim((string) $this->profile_photo_path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * The avatar-safe projection used where an endpoint hand-builds an
     * employee object instead of returning the model.
     *
     * @return array<string, mixed>
     */
    public function avatarSummary(): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'initials' => $this->initials,
            'avatar_initials' => $this->avatar_initials,
            'avatar_url' => $this->avatar_url,
            'profile_photo_path' => $this->profile_photo_path,
        ];
    }

    /**
     * Derive initials from whichever name data is available. Pure and static
     * so migrations, seeders and tests can use it without a model instance.
     */
    public static function initialsFor(?string $firstName, ?string $lastName, ?string $fullName = null): string
    {
        $first = self::cleanNamePart($firstName);
        $last = self::cleanNamePart($lastName);

        if ($first === '' && $last === '') {
            [$first, , $last] = self::splitFullName($fullName);
        }

        if ($first !== '' && $last !== '') {
            return mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));
        }

        if ($first !== '') {
            return mb_strtoupper(mb_substr($first, 0, 2));
        }

        if ($last !== '') {
            return mb_strtoupper(mb_substr($last, 0, 2));
        }

        return '--';
    }

    /**
     * Split a display name into [first, middle, last].
     *
     * Collapses repeated whitespace and splits on spaces only — apostrophes
     * (Nuhu's) and hyphens (Amina-Hawa) are part of the name itself.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function splitFullName(?string $fullName): array
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $fullName));

        if ($clean === '') {
            return ['', '', ''];
        }

        $parts = explode(' ', $clean);
        $first = (string) array_shift($parts);
        $last = $parts === [] ? '' : (string) array_pop($parts);

        return [$first, implode(' ', $parts), $last];
    }

    public static function composeFullName(?string $first, ?string $middle, ?string $last): string
    {
        $tokens = array_filter(
            [self::cleanNamePart($first), self::cleanNamePart($middle), self::cleanNamePart($last)],
            fn (string $part) => $part !== '',
        );

        return implode(' ', $tokens);
    }

    private static function cleanNamePart(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Organizational placement only — never a workflow scope. */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'division_id');
    }

    /** Organizational placement only — never a workflow scope. */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'unit_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'employee_roles', 'employee_id', 'role_id')
            ->withPivot(['department_id', 'scope_zone_id', 'source', 'effective_from', 'effective_to']);
    }

    public function reportingLines(): HasMany
    {
        return $this->hasMany(ReportingLine::class, 'employee_id');
    }

    public function supervised(): HasMany
    {
        return $this->hasMany(ReportingLine::class, 'supervisor_employee_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * Role IDs this employee holds unconditionally.
     *
     * Anything absent here is a scoped structural grant (department head of d1,
     * zonal head of z4). Only these may be written as unscoped rows; the rest are
     * owned by the single-grant endpoints.
     *
     * @return array<int, string>
     */
    public function unscopedRoleIds(): array
    {
        return $this->roles()
            ->wherePivotNull('department_id')
            ->wherePivotNull('scope_zone_id')
            ->pluck('roles.id')
            ->all();
    }
}

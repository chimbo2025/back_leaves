<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Name parts + profile photo for employee avatars.
 *
 * `employees.full_name` stays the authoritative display string (every report,
 * letter and notification reads it), so the new columns are additive:
 *
 *   - first_name / middle_name / last_name are nullable and backfilled from
 *     full_name for existing rows. They exist so an avatar can be derived
 *     deterministically ("Haji Ali Said" => HS) and so an admin form may post
 *     the parts directly.
 *   - profile_photo_path is nullable; NULL means "render the initials". No
 *     existing row has a photo, so nothing changes visually until one is set.
 *
 * The backfill only fills `initials` where it is still NULL/empty: seeded and
 * manually-set initials (e.g. "AK") are preserved exactly as HR recorded them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('first_name', 80)->nullable()->after('full_name');
            $table->string('middle_name', 80)->nullable()->after('first_name');
            $table->string('last_name', 120)->nullable()->after('middle_name');
            $table->string('profile_photo_path', 2048)->nullable()->after('email');
        });

        // Chunked so a large staff table never loads into memory at once.
        DB::table('employees')
            ->select(['id', 'full_name', 'initials'])
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    [$first, $middle, $last] = self::split((string) $row->full_name);

                    $update = [
                        'first_name' => $first === '' ? null : $first,
                        'middle_name' => $middle === '' ? null : $middle,
                        'last_name' => $last === '' ? null : $last,
                    ];

                    if (trim((string) $row->initials) === '') {
                        $update['initials'] = self::initials($first, $last);
                    }

                    DB::table('employees')->where('id', $row->id)->update($update);
                }
            });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'middle_name', 'last_name', 'profile_photo_path']);
        });
    }

    /**
     * "  Haji   Ali Said " => ['Haji', 'Ali', 'Said'].
     * Hyphens and apostrophes are part of a name, never separators.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function split(string $fullName): array
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $fullName));

        if ($clean === '') {
            return ['', '', ''];
        }

        $parts = explode(' ', $clean);
        $first = (string) array_shift($parts);
        $last = $parts === [] ? '' : (string) array_pop($parts);

        return [$first, implode(' ', $parts), $last];
    }

    private static function initials(string $first, string $last): string
    {
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
};

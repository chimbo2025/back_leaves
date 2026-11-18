<?php

namespace App\Support;

/**
 * Translation between the dynamic approval-role catalog (`approval_roles`,
 * codes such as `department_head`) and the legacy permission roles
 * (`roles`, ids such as `DEPT_HEAD`).
 *
 * Holders created before the catalog existed live in `employee_roles`; holders
 * created afterwards live in `employee_approval_roles`. The builder's holder
 * picker and the save-time "does this employee hold the role" check consult
 * both, so an administrator keeps seeing every eligible person.
 */
class ApprovalRoleMap
{
    /** @var array<string, string> */
    public const LEGACY_BY_CODE = [
        'supervisor' => 'SUPERVISOR',
        'department_head' => 'DEPT_HEAD',
        'zonal_head' => 'ZONAL_HEAD',
        'unit_head' => 'ROLE_UNIT_HEAD',
        'division_head' => 'ROLE_DIVISION_HEAD',
        'hr_reviewer' => 'HR',
        'hr_recorder' => 'HR_RECORDER',
        'deputy_dpp' => 'DEPUTY_DPP',
        'final_authority' => 'FINAL_AUTHORITY',
        'accounting_reviewer' => 'ACCOUNTING',
    ];

    public static function legacyRoleIdFor(string $approvalRoleCode): ?string
    {
        return self::LEGACY_BY_CODE[$approvalRoleCode] ?? null;
    }

    public static function approvalCodeFor(?string $legacyRoleId): ?string
    {
        if ($legacyRoleId === null || $legacyRoleId === '') {
            return null;
        }

        $match = array_search($legacyRoleId, self::LEGACY_BY_CODE, true);

        return $match === false ? null : $match;
    }

    /**
     * Every legacy role id that represents the same authority as an approval
     * role. Includes the upper-cased code itself so custom catalog roles whose
     * id/code was also granted through `employee_roles` are still recognised.
     *
     * @return list<string>
     */
    public static function legacyRoleIdsFor(string $approvalRoleCode): array
    {
        $ids = [];

        // Direct mapping from approval role code to legacy role id.
        if (isset(self::LEGACY_BY_CODE[$approvalRoleCode])) {
            $ids[] = self::LEGACY_BY_CODE[$approvalRoleCode];
        }

        // The roles table may store the id with a ROLE_ prefix (e.g.
        // ROLE_UNIT_HEAD). Try both the bare uppercased code and the
        // prefixed form so employees granted either variant are found.
        $upper = strtoupper($approvalRoleCode);
        $ids[] = $upper;
        $ids[] = 'ROLE_' . $upper;

        return array_values(array_unique(array_filter($ids)));
    }
}

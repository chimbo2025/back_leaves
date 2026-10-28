<?php

namespace App\Support;

/**
 * Resolved data-visibility scope for an authenticated user.
 *
 * The RBAC layer answers "which permission codes do you hold"; this object
 * answers the orthogonal question "which records may those codes be applied
 * to". Both must agree, otherwise a regional office holding requests.view
 * would still be able to read the whole country.
 */
class AccessScope
{
    /**
     * @param  array<int,string>  $allowedZoneIds
     * @param  array<int,string>  $allowedDepartmentIds
     */
    public function __construct(
        public bool $canViewAllZones = false,
        public array $allowedZoneIds = [],
        public array $allowedDepartmentIds = [],
        public bool $ownRecordsOnly = false,
    ) {}

    /** Deny-by-default fallback: no zone/department grant resolves to this. */
    public static function ownRecords(): self
    {
        return new self(ownRecordsOnly: true);
    }

    public function hasZoneScope(): bool
    {
        return $this->allowedZoneIds !== [] || $this->allowedDepartmentIds !== [];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'can_view_all_zones' => $this->canViewAllZones,
            'allowed_zone_ids' => $this->allowedZoneIds,
            'allowed_department_ids' => $this->allowedDepartmentIds,
            'own_records_only' => $this->ownRecordsOnly,
        ];
    }
}

/**
 * Seed the default approval-role catalog.
 *
 * Resolution types:
 *  'employee_approval_roles' — resolve from employee_approval_roles table
 *  'supervisor'              — resolve from reporting lines (supervisor)
 *  'both'                    — try employee_approval_roles first, then supervisor
 */
class ApprovalRoleSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['code' => 'supervisor', 'name' => 'Supervisor', 'name_sw' => 'Päällikkö', 'description' => 'Immediate supervisor from the reporting line.', 'resolution_type' => 'supervisor'],
            ['code' => 'department_head', 'name' => 'Department Head', 'name_sw' => 'Osaston päällikkö', 'description' => 'Head of the employee\'s department — required for long or regulated leave.', 'resolution_type' => 'supervisor'],
            ['code' => 'zonal_head', 'name' => 'Zonal Head', 'name_sw' => 'Aluepäällikkö', 'description' => 'Zone / regional head for offices outside HQ.', 'resolution_type' => 'supervisor'],
            ['code' => 'unit_head', 'name' => 'Unit Head', 'name_sw' => 'Yksikköpäällikkö', 'description' => 'Head of a subordinate organizational unit.', 'resolution_type' => 'supervisor'],
            ['code' => 'division_head', 'name' => 'Division Head', 'name_sw' => 'Osiojohtaja', 'description' => 'Head of a division within a department.', 'resolution_type' => 'supervisor'],
            ['code' => 'hr_reviewer', 'name' => 'HR Reviewer', 'name_sw' => 'HR‑arvioitsija', 'description' => 'HR reviewer — verifies policy compliance and balance.', 'resolution_type' => 'employee_approval_roles'],
            ['code' => 'hr_recorder', 'name' => 'HR Recorder', 'name_sw' => 'HR‑kirjaaja', 'description' => 'HR recorder — records documentation, no approval authority.', 'resolution_type' => 'employee_approval_roles'],
            ['code' => 'deputy_dpp', 'name' => 'Deputy DPP', 'name_sw' => 'VARA-DPP', 'description' => 'Deputy Director of Public Prosecutions.', 'resolution_type' => 'both'],
            ['code' => 'final_authority', 'name' => 'Final Authority', 'name_sw' => 'Vastuullinen', 'description' => 'Final sign‑off — DPP authority.', 'resolution_type' => 'both'],
            ['code' => 'accounting_reviewer', 'name' => 'Accounting Reviewer', 'name_sw' => 'Tilinpitäjä', 'description' => 'Reviews and processes leave-payment settlements.', 'resolution_type' => 'employee_approval_roles'],
        ];

        foreach ($defaults as $role) {
            // Use firstOrCreate with explicit id for string primary key compatibility
            $role['id'] = $role['code'];
            ApprovalRole::firstOrCreate(
                ['code' => $role['code']],
                array_merge(['id' => $role['id']], array_diff_key($role, ['code', 'id' => null]))
            );
        }
    }
}
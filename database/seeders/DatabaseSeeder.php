<?php

namespace Database\Seeders;

use App\Models\ApprovalAction;
use App\Models\ApprovalRole;
use App\Models\CaseHandover;
use App\Models\Delegation;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeApprovalRole;
use App\Models\EmployeeRole;
use App\Models\LeaveDocument;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Position;
use App\Models\ProsecutionCase;
use App\Models\ReportingLine;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowAssignment;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use App\Models\Zone;
use App\Services\AuditService;
use App\Services\WorkflowResolver;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the frontend demo dataset (lib/leave.ts) so every seeded id/ref/date
 * matches the UI prototype: zone, departments, positions, 12 employees+users,
 * reporting lines, built-in roles + role governance, leave types, all policy
 * versions, workflow definitions + steps, requests r1–r13 with their approval
 * chains and actions, delegations del1–del5, court+docket cases, handovers,
 * notifications and the hash-chained audit ledger.
 */
class DatabaseSeeder extends Seeder
{
    public function run(AuditService $audit): void
    {
        $this->clean();
        $this->seedZones();
        $this->seedDepartments();
        $this->seedPositions();
        $this->seedLeaveTypes();
        $this->seedUsers();
        $this->seedPolicies();
        $this->seedRoles();
        $this->seedPermissions();
        $this->seedRolePermissions();
        $this->seedApprovalRoles();
        $this->seedEmployees();
        $this->seedReportingLines();
        $this->seedEmployeeRoles();
        $this->seedEmployeeApprovalRoles();
        $this->seedWorkflows();
        $this->seedDelegations();
        $this->seedRequests($audit);
        $this->seedCases();
        $this->seedCourtCases();
        $this->seedNotifications();
        $this->seedAudit($audit);
    }

    protected function clean(): void
    {
        // During tests RefreshDatabase already starts with empty tables and
        // wraps everything in a transaction; TRUNCATE is DDL and would force
        // an implicit commit, so it must be skipped.
        if (app()->runningUnitTests()) {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['report_exports', 'official_leave_letters', 'approval_signatures', 'leave_payment_cases', 'notification_deliveries', 'notification_preferences', 'audit_logs', 'notifications', 'case_handovers', 'cases', 'delegations', 'approval_actions', 'workflow_assignments', 'leave_request_dates', 'leave_returns', 'leave_documents', 'leave_requests', 'leave_plan_options', 'leave_plans', 'balance_transactions', 'leave_waivers', 'workflow_steps', 'workflow_definitions', 'employee_approval_roles', 'approval_roles', 'employee_roles', 'reporting_lines', 'position_default_roles', 'role_permissions', 'permissions', 'roles', 'leave_policies', 'leave_types', 'employees', 'users', 'positions', 'departments', 'zones', 'personal_access_tokens'] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function seedZones(): void
    {
        $rows = [
            ['id' => 'z1', 'name' => 'Unguja Island — Zanzibar Town', 'short' => 'Unguja (HQ)', 'is_hq' => true, 'is_pemba' => false],
            ['id' => 'z2', 'name' => 'Unguja North', 'short' => 'Unguja Kaskazini', 'is_hq' => false, 'is_pemba' => false],
            ['id' => 'z3', 'name' => 'Unguja South', 'short' => 'Unguja Kusini', 'is_hq' => false, 'is_pemba' => false],
            ['id' => 'z4', 'name' => 'Pemba — Wete Coordination Office', 'short' => 'Pemba', 'is_hq' => false, 'is_pemba' => true, 'is_coordination' => true],
        ];
        foreach ($rows as $row) {
            Zone::create($row);
        }
    }

    protected function seedDepartments(): void
    {
        $rows = [
            ['id' => 'd1', 'name' => 'Administration & Human Resources', 'zone_id' => 'z1', 'unit_type' => 'division', 'reports_directly_to_leadership' => true],
            ['id' => 'd2', 'name' => 'Prosecutions', 'zone_id' => 'z1', 'unit_type' => 'division', 'reports_directly_to_leadership' => true],
            ['id' => 'd3', 'name' => 'Legal Research & Advisory', 'zone_id' => 'z1', 'parent_department_id' => 'd2', 'unit_type' => 'unit', 'reports_directly_to_leadership' => false],
            ['id' => 'd4', 'name' => 'Regional Prosecutions — Unguja North', 'zone_id' => 'z2', 'parent_department_id' => 'd2', 'unit_type' => 'region', 'reports_directly_to_leadership' => false],
            ['id' => 'd5', 'name' => 'Regional Prosecutions — Pemba', 'zone_id' => 'z4', 'parent_department_id' => 'd2', 'unit_type' => 'region', 'reports_directly_to_leadership' => false],
            ['id' => 'd6', 'name' => 'ICT', 'zone_id' => 'z1', 'parent_department_id' => 'd1', 'unit_type' => 'unit', 'reports_directly_to_leadership' => false],
            ['id' => 'd7', 'name' => 'Records & Archives', 'zone_id' => 'z1', 'parent_department_id' => 'd1', 'unit_type' => 'unit', 'reports_directly_to_leadership' => false],
            ['id' => 'd8', 'name' => 'Finance', 'zone_id' => 'z1', 'parent_department_id' => 'd1', 'unit_type' => 'unit', 'reports_directly_to_leadership' => false],
        ];
        foreach ($rows as $row) {
            Department::create($row);
        }
    }

    protected function seedPositions(): void
    {
        // Positions are job titles only: no approval tier, no final-authority
        // flag and no default roles. Authority comes from employee_roles.
        $rows = [
            ['id' => 'p1', 'code' => 'director_public_prosecutions', 'title' => 'Director of Public Prosecutions', 'department_id' => 'd2'],
            ['id' => 'p2', 'code' => 'deputy_director_public_prosecutions', 'title' => 'Deputy Director of Public Prosecutions', 'department_id' => 'd2'],
            ['id' => 'p3', 'code' => 'director_administration_hr', 'title' => 'Director, Administration & Human Resources', 'department_id' => 'd1'],
            ['id' => 'p4', 'code' => 'zonal_regional_head', 'title' => 'Zonal / Regional Head', 'department_id' => 'd4'],
            ['id' => 'p5', 'code' => 'head_of_prosecutions', 'title' => 'Head of Prosecutions', 'department_id' => 'd2'],
            ['id' => 'p6', 'code' => 'senior_state_attorney', 'title' => 'Senior State Attorney', 'department_id' => 'd2'],
            ['id' => 'p7', 'code' => 'state_attorney_i', 'title' => 'State Attorney I', 'department_id' => 'd2'],
            ['id' => 'p8', 'code' => 'state_attorney_ii', 'title' => 'State Attorney II', 'department_id' => 'd2'],
            ['id' => 'p9', 'code' => 'human_resources_officer', 'title' => 'Human Resources Officer', 'department_id' => 'd1'],
            ['id' => 'p10', 'code' => 'ict_officer', 'title' => 'ICT Officer', 'department_id' => 'd6'],
            ['id' => 'p11', 'code' => 'records_officer', 'title' => 'Records Officer', 'department_id' => 'd7'],
            ['id' => 'p12', 'code' => 'executive_assistant', 'title' => 'Executive Assistant', 'department_id' => 'd1'],
        ];
        foreach ($rows as $row) {
            Position::create($row + ['is_active' => true]);
        }
    }

    protected function seedLeaveTypes(): void
    {
        $rows = [
            ['id' => 'lt1', 'code' => 'ANNUAL', 'name' => 'Annual Leave', 'swahili' => 'Likizo ya Mwaka', 'short' => 'Mwaka', 'is_paid_default' => true, 'description' => 'Annual recreational leave accrued per service year.'],
            ['id' => 'lt2', 'code' => 'SICK', 'name' => 'Sick Leave', 'swahili' => 'Likizo ya Ugonjwa', 'short' => 'Ugonjwa', 'is_paid_default' => true, 'description' => 'Leave for certified illness or injury.'],
            ['id' => 'lt3', 'code' => 'MATERNITY', 'name' => 'Maternity Leave', 'swahili' => 'Likizo ya Uzazi', 'short' => 'Uzazi', 'is_paid_default' => true, 'description' => 'Statutory leave for mothers before and after delivery.'],
            ['id' => 'lt4', 'code' => 'PATERNITY', 'name' => 'Paternity Leave', 'swahili' => 'Likizo ya Baba', 'short' => 'Baba', 'is_paid_default' => true, 'description' => 'Short leave for fathers following the birth of a child.'],
            ['id' => 'lt5', 'code' => 'EMERGENCY', 'name' => 'Emergency Leave', 'swahili' => 'Likizo ya Dharura', 'short' => 'Dharura', 'is_paid_default' => true, 'description' => 'Urgent personal or family emergencies.'],
            ['id' => 'lt6', 'code' => 'STUDY', 'name' => 'Study Leave', 'swahili' => 'Likizo ya Masomo', 'short' => 'Masomo', 'is_paid_default' => true, 'description' => 'Leave to pursue approved academic or professional training.'],
            ['id' => 'lt7', 'code' => 'WITHOUT_PAY', 'name' => 'Leave Without Pay', 'swahili' => 'Likizo Bila Malipo', 'short' => 'Bila Malipo', 'is_paid_default' => false, 'description' => 'Unpaid leave approved for personal reasons.'],
        ];
        foreach ($rows as $row) {
            LeaveType::create($row);
        }
    }

    protected function seedPolicies(): void
    {
        $rows = [
            ['id' => 'pol1_v1', 'leave_type_id' => 'lt1', 'version' => 1, 'max_days_per_year' => 24, 'max_days_per_request' => 24, 'min_tenure_months' => 12, 'min_notice_days' => 14, 'requires_documents' => false, 'required_docs' => [], 'carry_over_max' => 5, 'carry_over_expiry_month' => null, 'allow_half_day' => true, 'notes' => 'Original annual policy — carry-over capped at 5 days.', 'effective_from' => '2023-04-01', 'effective_to' => '2024-03-31'],
            ['id' => 'pol1_v3', 'leave_type_id' => 'lt1', 'version' => 3, 'max_days_per_year' => 27, 'max_days_per_request' => 27, 'min_tenure_months' => 12, 'min_notice_days' => 14, 'requires_documents' => false, 'required_docs' => [], 'carry_over_max' => 8, 'carry_over_expiry_month' => 3, 'allow_half_day' => true, 'notes' => 'Annual policy v3 — 27 days, carry-over raised to 8.', 'effective_from' => '2024-04-01', 'effective_to' => '2026-06-15'],
            ['id' => 'pol1', 'leave_type_id' => 'lt1', 'version' => 4, 'max_days_per_year' => 30, 'max_days_per_request' => 30, 'min_tenure_months' => 12, 'min_notice_days' => 14, 'requires_documents' => false, 'required_docs' => [], 'carry_over_max' => 10, 'carry_over_expiry_month' => 3, 'allow_half_day' => true, 'notes' => 'Current annual policy v4 — 30 days, carry-over raised to 10. >20 days requires final authority.', 'effective_from' => '2026-06-16', 'effective_to' => null],
            ['id' => 'pol2_v1', 'leave_type_id' => 'lt2', 'version' => 1, 'max_days_per_year' => 10, 'max_days_per_request' => 10, 'min_tenure_months' => 0, 'min_notice_days' => 1, 'requires_documents' => true, 'required_docs' => ['Medical certificate'], 'carry_over_max' => 0, 'allow_half_day' => true, 'notes' => 'Original sick policy — 10 days.', 'effective_from' => '2024-01-01', 'effective_to' => '2025-08-01'],
            ['id' => 'pol2_v2', 'leave_type_id' => 'lt2', 'version' => 2, 'max_days_per_year' => 12, 'max_days_per_request' => 12, 'min_tenure_months' => 0, 'min_notice_days' => 1, 'requires_documents' => true, 'required_docs' => ['Medical certificate'], 'carry_over_max' => 0, 'allow_half_day' => true, 'notes' => 'Sick leave v2 — 12 days, extended for seasonal malaria.', 'effective_from' => '2025-08-02', 'effective_to' => '2026-03-01'],
            ['id' => 'pol2', 'leave_type_id' => 'lt2', 'version' => 3, 'max_days_per_year' => 14, 'max_days_per_request' => 14, 'min_tenure_months' => 0, 'min_notice_days' => 1, 'requires_documents' => true, 'required_docs' => ['Medical certificate'], 'carry_over_max' => 0, 'allow_half_day' => true, 'notes' => 'Current sick policy v3 — 14 days. Medical certificate mandatory beyond 3 days.', 'effective_from' => '2026-03-02', 'effective_to' => null],
            ['id' => 'pol3_v1', 'leave_type_id' => 'lt3', 'version' => 1, 'max_days_per_year' => 84, 'max_days_per_request' => 84, 'min_tenure_months' => 6, 'min_notice_days' => 7, 'requires_documents' => true, 'required_docs' => ['Antenatal record', 'Birth certificate (post-delivery)'], 'carry_over_max' => 0, 'allow_half_day' => false, 'notes' => 'Original maternity policy — 84 days, 6 months tenure.', 'effective_from' => '2023-01-01', 'effective_to' => '2025-10-01'],
            ['id' => 'pol3', 'leave_type_id' => 'lt3', 'version' => 2, 'max_days_per_year' => 84, 'max_days_per_request' => 84, 'min_tenure_months' => 6, 'min_notice_days' => 7, 'requires_documents' => true, 'required_docs' => ['Antenatal record', 'Birth certificate (post-delivery)'], 'carry_over_max' => 0, 'allow_half_day' => false, 'notes' => 'Current maternity policy v2 — final authority approval required, acting cover mandatory.', 'effective_from' => '2025-10-02', 'effective_to' => null],
            ['id' => 'pol4', 'leave_type_id' => 'lt4', 'version' => 2, 'max_days_per_year' => 7, 'max_days_per_request' => 7, 'min_tenure_months' => 0, 'min_notice_days' => 1, 'requires_documents' => true, 'required_docs' => ['Birth certificate'], 'carry_over_max' => 0, 'allow_half_day' => false, 'notes' => 'Medical/birth documentation held privately by HR only.', 'effective_from' => '2024-01-01', 'effective_to' => null],
            ['id' => 'pol5', 'leave_type_id' => 'lt5', 'version' => 2, 'max_days_per_year' => 5, 'max_days_per_request' => 5, 'min_tenure_months' => 0, 'min_notice_days' => 0, 'requires_documents' => false, 'required_docs' => [], 'carry_over_max' => 0, 'allow_half_day' => false, 'notes' => 'Zero notice period. Supporting explanation recorded at submission.', 'effective_from' => '2024-01-01', 'effective_to' => null],
            ['id' => 'pol6', 'leave_type_id' => 'lt6', 'version' => 1, 'max_days_per_year' => 120, 'max_days_per_request' => 120, 'min_tenure_months' => 24, 'min_notice_days' => 45, 'requires_documents' => true, 'required_docs' => ['Admission / enrolment letter', 'Study plan'], 'carry_over_max' => 0, 'allow_half_day' => false, 'notes' => 'Pemba requests add a Zonal Head step. Final authority approval always required.', 'effective_from' => '2024-01-01', 'effective_to' => null],
            ['id' => 'pol7', 'leave_type_id' => 'lt7', 'version' => 1, 'max_days_per_year' => 60, 'max_days_per_request' => 60, 'min_tenure_months' => 0, 'min_notice_days' => 14, 'requires_documents' => false, 'required_docs' => [], 'carry_over_max' => 0, 'allow_half_day' => false, 'notes' => 'Not applicable during probation. Final authority approval required.', 'effective_from' => '2024-01-01', 'effective_to' => null],
        ];
        foreach ($rows as $row) {
            LeavePolicy::create(array_merge($row, ['created_by' => 'u3', 'approved_by' => 'u1']));
        }
    }

    protected function seedRoles(): void
    {
        // description explains what the role is FOR, and deliberately spells out
        // that scope comes from the assignment, not from the role itself: the same
        // DEPT_HEAD is d1-only or d5-only depending on the grant.
        $rows = [
            ['id' => 'SUPERVISOR', 'name' => 'Immediate Supervisor', 'description' => 'Line manager for day-to-day leave approval within their own reporting line.', 'approval_tier' => 1, 'is_final_authority' => false, 'is_system' => true],
            ['id' => 'DEPT_HEAD', 'name' => 'Department Head', 'description' => 'Heads one department. Scope comes from the department grant; can approve, return and reject without any zone reach.', 'approval_tier' => 2, 'is_final_authority' => false, 'is_system' => true],
            ['id' => 'ZONAL_HEAD', 'name' => 'Zone / Regional Head', 'description' => 'Heads one zone. Scope comes from the zone grant; can approve within that zone only.', 'approval_tier' => 3, 'is_final_authority' => false, 'is_system' => true],
            ['id' => 'HR', 'name' => 'Human Resources', 'description' => 'Central HR. Cross-zone read for requests, staff and reports; administers staff, roles and leave policy.', 'approval_tier' => 4, 'is_final_authority' => false, 'is_system' => true],
            ['id' => 'FINAL_AUTHORITY', 'name' => 'Final Authority — DPP', 'description' => 'Final sign-off. Holds every catalogued permission explicitly, so the RBAC matrix shows exactly what the role can do. Organisation-wide reach comes from its four view_cross_zone codes.', 'approval_tier' => 5, 'is_final_authority' => true, 'is_system' => true],
            ['id' => 'BREAK_GLASS', 'name' => 'Break-Glass Authority', 'description' => 'Emergency recovery access, identical in reach to the Final Authority. Every grant is capped at 30 days so it cannot quietly become a standing privilege.', 'approval_tier' => 5, 'is_final_authority' => false, 'is_system' => true],
            // Carries PermissionService::SELF_SERVICE. This used to be a merge
            // inside codesFor() that no admin could revoke; as a role it is
            // visible in the matrix and assignable like any other.
            ['id' => 'SELF_SERVICE', 'name' => 'Self-Service', 'description' => 'Baseline for every active employee: read and file their own leave, read leave policy, see their own documents. No approval rights, no cross-zone reach.', 'approval_tier' => 0, 'is_final_authority' => false, 'is_system' => true],
            // ACCOUNTING owns the settlement capability; it is granted cross-zone
            // read so the office sees every approved payment case, and the two
            // Accounting codes so it can receive and process them. HR never
            // receives/processes, so the office that approves cannot be the
            // office that pays.
            ['id' => 'ACCOUNTING', 'name' => 'Finance / Accounting', 'description' => 'Finance office. Receives approved payment cases and settles them. Holds leave_payments.view_cross_zone, receive and process. Never approves or rejects.', 'approval_tier' => 4, 'is_final_authority' => false, 'is_system' => true],
        ];
        foreach ($rows as $row) {
            Role::create($row);
        }

        // Phase 2 — NO DEFAULT approval roles seeded.
        // Roles are created dynamically by admins per-organization needs.
        // All must be deletable: workflow steps must re-reference roles safely
        // or fall back to the legacy role_required string (backward compat).
        // ApprovalRole model carries is_system_protected=false on every row.
    }

    /**
     * Permission matrix from the frontend SEED_ROLE_PERMISSIONS, flattened into
     * code=module.action rows. Standard actions (view/create/update/delete/
     * approve) plus granular codes (approve_zone, edit_policy,
     * view_medical_documents, view_gender_reports, view_cross_zone).
     *
     * Every code listed here must be reachable from an enforcement point: route
     * middleware (`permission:`), a policy or a service calling
     * PermissionService::can(). Codes that no endpoint or policy consults are
     * removed by migration 2026_10_03_120000, because a switch in the RBAC
     * matrix that changes nothing is worse than no switch at all.
     *
     * requests.approve is enforced by RequestController::decide alongside
     * assignment ownership (capability AND ownership); requests.approve_zone
     * stays as the frontend-only Approvals-surface signal for zone oversight.
     */
    protected function seedPermissions(): void
    {
        $meta = [
            'approve_zone' => ['module' => 'requests', 'label' => 'approve_zone', 'hint' => 'Approve leave for staff outside your own zone (regional oversight).'],
            'view_medical_documents' => ['module' => 'documents', 'label' => 'view_medical_documents', 'hint' => 'Reveal restricted medical & birth records (HR privacy gate).'],
            'edit_policy' => ['module' => 'policies', 'label' => 'edit_policy', 'hint' => 'Revise leave-type policies and approval workflows.'],
            'view_gender_reports' => ['module' => 'reports', 'label' => 'view_gender_reports', 'hint' => 'Unlock gender-split reporting (restricted personnel data).'],
            'view_cross_zone' => ['module' => 'requests', 'label' => 'view_cross_zone', 'hint' => 'View staff and leave records across ALL zones/departments. Central offices only; regional roles stay zone-scoped.'],
            'view_own' => ['module' => 'reports', 'label' => 'view_own', 'hint' => 'View your own leave reports.'],
            'view_department' => ['module' => 'reports', 'label' => 'view_department', 'hint' => 'View leave reports for your department.'],
            'view_zone' => ['module' => 'reports', 'label' => 'view_zone', 'hint' => 'View leave reports for your zone.'],
            'view_organisation' => ['module' => 'reports', 'label' => 'view_organisation', 'hint' => 'View leave reports across the entire organisation.'],
            'export' => ['module' => 'reports', 'label' => 'export', 'hint' => 'Export leave reports to CSV/PDF.'],
            'view_audit' => ['module' => 'reports', 'label' => 'view_audit', 'hint' => 'View audit log reports.'],
            'view_financial' => ['module' => 'reports', 'label' => 'view_financial', 'hint' => 'View financial leave reports (payments, balances).'],
            'view_sensitive' => ['module' => 'reports', 'label' => 'view_sensitive', 'hint' => 'View sensitive leave reports (medical, full history).'],
            'download' => ['module' => 'report_exports', 'label' => 'download', 'hint' => 'Download generated report exports.'],
            'cancel' => ['module' => 'report_exports', 'label' => 'cancel', 'hint' => 'Cancel pending report exports.'],
            'view' => ['module' => 'audit_logs', 'label' => 'view', 'hint' => 'View audit log entries.'],
        ];

        // ASSUMPTION (per brief, no HQ role exists in the seeded role set):
        // HR = central HR office and FINAL_AUTHORITY = DPP are the only roles
        // granted organisation-wide reach. ZONAL_HEAD/DEPT_HEAD deliberately
        // keep NO view_cross_zone — they are restricted to their own
        // scope_zone_id / department_id grants instead.
        $matrix = [
            'SELF_SERVICE' => ['requests' => ['view', 'create', 'view_own', 'edit_own', 'submit', 'withdraw'], 'policies' => ['view'], 'documents' => ['view'], 'staff' => ['view_own'], 'approval_workflows' => ['view'], 'case_handovers' => ['view', 'create', 'accept', 'reject', 'complete'], 'leave_plans' => ['view_own', 'create_own', 'select_own'], 'leave_waivers' => ['create_own', 'view_own'], 'leave_returns' => ['report_own', 'view_own'], 'leave_payments' => ['view_own'], 'leave_documents' => ['view_own', 'download_own'], 'notifications' => ['view_own', 'manage_preferences'], 'reports' => ['view_own']],
            // The three delegable roles carry the delegation codes themselves.
            // StoreDelegationRequest restricts role_id to SUPERVISOR, DEPT_HEAD and
            // ZONAL_HEAD, so a matrix that granted delegations.create only to HR
            // made the whole feature unreachable: the roles that are allowed to
            // delegate were denied permission to delegate, and the roles with the
            // permission were refused by validation. A permission nobody can
            // exercise is the same decorative switch the catalog audit is about.
            'SUPERVISOR' => ['requests' => ['view', 'create', 'approve', 'view_own', 'edit_own', 'submit', 'withdraw', 'reject', 'return'], 'policies' => ['view'], 'documents' => ['view'], 'staff' => ['view_own'], 'delegations' => ['view', 'create', 'update'], 'reports' => ['view'], 'approval_workflows' => ['view'], 'case_handovers' => ['view', 'create', 'accept', 'reject', 'complete'], 'leave_documents' => ['view_own', 'download_own']],
            'DEPT_HEAD' => ['requests' => ['view', 'approve', 'view_own', 'edit_own', 'submit', 'withdraw', 'reject', 'return'], 'delegations' => ['view', 'create', 'update'], 'staff' => ['view_own'], 'reports' => ['view', 'view_department'], 'approval_workflows' => ['view'], 'case_handovers' => ['view', 'create', 'accept', 'reject', 'complete'], 'leave_plans' => ['view_department', 'confirm_department'], 'leave_waivers' => ['view_department', 'review', 'reject'], 'leave_returns' => ['view_department', 'review', 'excuse', 'apply_deduction', 'close'], 'leave_documents' => ['view_own', 'download_own']],
            'ZONAL_HEAD' => ['requests' => ['view', 'approve', 'approve_zone', 'view_own', 'edit_own', 'submit', 'withdraw', 'reject', 'return'], 'delegations' => ['view', 'create', 'update'], 'staff' => ['view_own'], 'reports' => ['view', 'view_zone'], 'approval_workflows' => ['view'], 'case_handovers' => ['view', 'create', 'accept', 'reject', 'complete'], 'leave_plans' => ['view_zone', 'confirm_zone'], 'leave_waivers' => ['view_zone', 'review', 'reject'], 'leave_returns' => ['view_zone', 'review', 'excuse', 'apply_deduction', 'close'], 'leave_documents' => ['view_own', 'download_own']],
            'HR' => ['requests' => ['view', 'create', 'approve', 'approve_zone', 'view_cross_zone', 'view_own', 'edit_own', 'submit', 'withdraw', 'reject', 'return'], 'policies' => ['view', 'create', 'edit_policy'], 'documents' => ['view', 'create', 'delete', 'view_medical_documents'], 'staff' => ['view_cross_zone', 'view_own'], 'delegations' => ['view', 'create', 'update'], 'reports' => ['view', 'view_gender_reports', 'view_cross_zone', 'view_own', 'view_department', 'view_zone', 'view_organisation', 'export', 'view_audit', 'view_financial', 'view_sensitive'], 'admin' => ['view', 'create', 'update', 'delete'], 'approval_workflows' => ['view', 'create', 'update'], 'case_handovers' => ['view', 'create', 'accept', 'reject', 'complete'], 'leave_plans' => ['view_own', 'create_own', 'select_own', 'view_department', 'view_zone', 'view_cross_zone', 'confirm_department', 'confirm_zone'], 'leave_waivers' => ['create_own', 'view_own', 'review', 'approve', 'reject', 'view_department', 'view_zone', 'view_cross_zone'], 'leave_returns' => ['report_own', 'view_own', 'view_department', 'view_zone', 'view_cross_zone', 'review', 'excuse', 'apply_deduction', 'close'], 'leave_payments' => ['view_own', 'view_department', 'view_zone', 'view_cross_zone', 'create', 'review', 'approve', 'reject', 'cancel'], 'leave_documents' => ['view_own', 'download_own', 'download_hr', 'generate', 'revoke'], 'notifications' => ['view_own', 'manage_preferences', 'view_sensitive'], 'report_exports' => ['download', 'cancel'], 'leave_requests' => ['force_delete'], 'employees' => ['force_delete']],
            'FINAL_AUTHORITY' => ['requests' => ['view', 'approve', 'view_cross_zone'], 'reports' => ['view', 'view_gender_reports', 'view_cross_zone'], 'staff' => ['view_cross_zone']],
        ];

        // FINAL_AUTHORITY and BREAK_GLASS are the two authority roles and must
        // carry the WHOLE catalog, enumerated. They used to resolve to the
        // sentinel `['*']` instead, which granted every code that existed plus
        // every code added later while the matrix displayed only 7 — and whose
        // short-circuit made them org-wide on any module name, not just the
        // four that define a view_cross_zone code. Enumerating restores exactly
        // today's effective access and stops future codes being absorbed
        // silently. Assignment paths cap BREAK_GLASS at 30 days.
        $authorityProfile = [
            'requests' => ['view', 'create', 'approve', 'approve_zone', 'view_cross_zone', 'view_own', 'edit_own', 'submit', 'withdraw', 'reject', 'return', 'view_department', 'view_zone', 'cancel'],
            'policies' => ['view', 'create', 'edit_policy', 'update', 'archive', 'publish'],
            'documents' => ['view', 'create', 'delete', 'view_medical_documents'],
            'staff' => ['view_cross_zone', 'view_own', 'view_department', 'view_zone'],
            'delegations' => ['view', 'create', 'update'],
            'reports' => ['view', 'view_gender_reports', 'view_cross_zone', 'view_own', 'view_department', 'view_zone', 'view_organisation', 'export', 'view_audit', 'view_financial', 'view_sensitive'],
            'admin' => ['view', 'create', 'update', 'delete'],
            'leave_requests' => ['force_delete'],
            'employees' => ['force_delete'],
            'approval_workflows' => ['view', 'create', 'update', 'delete', 'activate', 'archive', 'test'],
            'case_handovers' => ['view', 'create', 'accept', 'reject', 'complete', 'cancel'],
            'leave_plans' => ['view_own', 'create_own', 'select_own', 'view_department', 'view_zone', 'view_cross_zone', 'confirm_department', 'confirm_zone'],
            'leave_waivers' => ['create_own', 'view_own', 'review', 'approve', 'reject', 'view_department', 'view_zone', 'view_cross_zone'],
            'leave_returns' => ['report_own', 'view_own', 'view_department', 'view_zone', 'view_cross_zone', 'review', 'excuse', 'apply_deduction', 'close'],
            'leave_payments' => ['view_own', 'view_department', 'view_zone', 'view_cross_zone', 'create', 'review', 'approve', 'reject', 'receive', 'process', 'cancel'],
            'leave_documents' => ['view_own', 'download_own', 'download_hr', 'generate', 'revoke'],
            'notifications' => ['view_own', 'manage_preferences', 'view_sensitive'],
            'report_exports' => ['download', 'cancel'],
            'audit_logs' => ['view'],
        ];

        foreach (['FINAL_AUTHORITY', 'BREAK_GLASS'] as $authorityRole) {
            $matrix[$authorityRole] = $authorityProfile;
        }

        // ACCOUNTING: settlement only, no approval/reject, cross-zone read to see
        // every case. Not granted to HR to keep the approval/settlement separation.
        // No official document permissions for Accounting.
        $matrix['ACCOUNTING'] = [
            'leave_payments' => ['view_cross_zone', 'receive', 'process'],
            'leave_documents' => [],
        ];

        $permissionIds = [];

        foreach ($matrix as $roleId => $modules) {
            foreach ($modules as $module => $actions) {
                foreach ($actions as $action) {
                    $code = "{$module}.{$action}";
                    // firstOrCreate: the cross-zone migration may already have
                    // inserted these codes, so seeding must stay idempotent.
                    $permissionIds[$code] ??= Permission::firstOrCreate(
                        ['code' => $code],
                        [
                            'module' => $module,
                            'action' => $action,
                            'label' => $meta[$action]['label'] ?? $action,
                            'hint' => $meta[$action]['hint'] ?? "{$module} {$action}",
                        ],
                    )->id;
                    Role::find($roleId)->permissions()->syncWithoutDetaching([$permissionIds[$code]]);
                }
            }
        }

        // Migrations create only a handful of these codes; the rest are
        // firstOrCreate'd above. Applying lifecycle metadata only in the
        // migration left those rows claiming status=implemented with no
        // enforcement_reference, so the claim has to be made here too, for
        // whichever rows now exist.
        Permission::applyLifecycleDefaults();
    }

    protected function seedRolePermissions(): void
    {
        // handled inside seedPermissions() per-role
    }

    protected function seedUsers(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            User::create([
                'id' => "u{$i}",
                'username' => "u{$i}",
                'password' => 'password',
                'is_active' => true,
            ]);
        }
    }

    protected $employees = [
        ['e1', 'u1', 'Ali Mwinyi Khamis', 'AK', 'p1', 'd2', 'z1', 'M', '2010-01-04', 'ali.khamis@dpp.go.tz', 'DPP-1001'],
        ['e2', 'u2', 'Salma Ali Juma', 'SJ', 'p2', 'd2', 'z1', 'F', '2012-06-18', 'salma.juma@dpp.go.tz', 'DPP-1002'],
        ['e3', 'u3', 'Fatma Abdalla Hassan', 'FH', 'p3', 'd1', 'z1', 'F', '2011-03-01', 'fatma.hassan@dpp.go.tz', 'DPP-1003'],
        ['e4', 'u4', 'Rashid Haji Rashid', 'RR', 'p4', 'd4', 'z2', 'M', '2013-09-15', 'rashid.rashid@dpp.go.tz', 'DPP-1004'],
        ['e5', 'u5', 'Juma Mohammed Ali', 'JA', 'p4', 'd5', 'z4', 'M', '2012-11-05', 'juma.ali@dpp.go.tz', 'DPP-1005'],
        ['e6', 'u6', 'Zaytuni Omar Juma', 'ZJ', 'p5', 'd2', 'z1', 'F', '2014-02-20', 'zaytuni.juma@dpp.go.tz', 'DPP-1006'],
        ['e7', 'u7', 'Asha Hassan Haji', 'AH', 'p8', 'd2', 'z1', 'F', '2018-05-10', 'asha.haji@dpp.go.tz', 'DPP-1007'],
        ['e8', 'u8', 'Mohammed Ali Salum', 'MS', 'p7', 'd4', 'z2', 'M', '2016-08-22', 'mohammed.salum@dpp.go.tz', 'DPP-1008'],
        ['e9', 'u9', 'Rehema Mzuri Hamad', 'RH', 'p7', 'd5', 'z4', 'F', '2017-01-30', 'rehema.hamad@dpp.go.tz', 'DPP-1009'],
        ['e10', 'u10', 'Baraka Said Maulid', 'BM', 'p10', 'd6', 'z1', 'M', '2019-04-08', 'baraka.maulid@dpp.go.tz', 'DPP-1010'],
        ['e11', 'u11', 'Khadija Suleiman Omar', 'KO', 'p11', 'd7', 'z1', 'F', '2020-07-14', 'khadija.omar@dpp.go.tz', 'DPP-1011'],
        ['e12', 'u12', 'Haji Mwinyi Abdullah', 'HA', 'p12', 'd1', 'z1', 'M', '2021-10-02', 'haji.abdullah@dpp.go.tz', 'DPP-1012'],
    ];

    protected function seedEmployees(): void
    {
        foreach ($this->employees as [$id, $userId, $name, $initials, $positionId, $deptId, $zoneId, $gender, $hireDate, $email, $payrollNo]) {
            Employee::create([
                'id' => $id, 'user_id' => $userId, 'payroll_no' => $payrollNo, 'full_name' => $name, 'initials' => $initials,
                'position_id' => $positionId, 'department_id' => $deptId, 'zone_id' => $zoneId,
                'gender' => $gender, 'email' => $email, 'hire_date' => $hireDate, 'is_active' => true,
            ]);
        }
    }

    /** REPORTING map → primary reporting lines. e1 (DPP) reports to no one. */
    protected function seedReportingLines(): void
    {
        $rows = [
            ['e7', 'e6'], ['e6', 'e2'], ['e8', 'e4'], ['e9', 'e5'], ['e2', 'e1'],
            ['e3', 'e1'], ['e4', 'e1'], ['e5', 'e1'], ['e10', 'e3'], ['e11', 'e3'], ['e12', 'e3'],
        ];
        foreach ($rows as [$emp, $sup]) {
            ReportingLine::create([
                'employee_id' => $emp, 'supervisor_employee_id' => $sup, 'relationship_type' => 'primary',
                'effective_from' => '2020-01-01', 'effective_to' => null,
            ]);
        }
    }

    /**
     * standingHolder mapping — EMPLOYEE_ROLES plus DEPT_HEADS scope. e3 covers
     * d1/d6/d7/d8 as DEPT_HEAD; e2 heads d3; e4/e5 head d4/d5 and zone z2/z4.
     */
    protected function seedEmployeeRoles(): void
    {
        $rows = [
            ['e1', 'FINAL_AUTHORITY', null, null, 'position_default', 'u1'],
            ['e3', 'HR', null, null, 'position_default', 'u1'],
            ['e3', 'DEPT_HEAD', 'd1', null, 'head_of_department', 'u1'],
            ['e3', 'DEPT_HEAD', 'd6', null, 'head_of_department', 'u1'],
            ['e3', 'DEPT_HEAD', 'd7', null, 'head_of_department', 'u1'],
            ['e3', 'DEPT_HEAD', 'd8', null, 'head_of_department', 'u1'],
            ['e2', 'DEPT_HEAD', 'd3', null, 'head_of_department', 'u1'],
            ['e4', 'DEPT_HEAD', 'd4', null, 'head_of_department', 'u1'],
            ['e4', 'ZONAL_HEAD', null, 'z2', 'zonal_head', 'u1'],
            ['e5', 'DEPT_HEAD', 'd5', null, 'head_of_department', 'u1'],
            ['e5', 'ZONAL_HEAD', null, 'z4', 'zonal_head', 'u1'],
            ['e6', 'DEPT_HEAD', 'd2', null, 'head_of_department', 'u1'],
            ['e6', 'SUPERVISOR', null, null, 'position_default', 'u1'],
            ['e7', 'SUPERVISOR', null, null, 'position_default', 'u1'],
        ];
        foreach ($rows as [$emp, $role, $dept, $zone, $source, $grantedBy]) {
            EmployeeRole::create([
                'employee_id' => $emp, 'role_id' => $role, 'department_id' => $dept, 'scope_zone_id' => $zone,
                'source' => $source, 'granted_by' => $grantedBy, 'effective_from' => '2020-01-01', 'effective_to' => null,
            ]);
        }

        // Every active employee keeps the self-service floor. Without it a plain
        // employee holds no codes and My Leave comes back empty behind a 403.
        //
        // This is unconditional. It used to skip anyone who already appeared in
        // $rows, which quietly meant the floor was only given to roleless staff:
        // a supervisor lost policies.view / documents.view purely for being a
        // supervisor. It now grants SELF_SERVICE to every active employee that
        // does not already hold it, so the role is the floor for the whole
        // workforce rather than a consolation prize for the ones with no role.
        $holdsSelfService = collect($rows)
            ->filter(fn ($row) => $row[1] === 'SELF_SERVICE')
            ->pluck(0)
            ->unique();

        foreach (Employee::where('is_active', 1)->get() as $employee) {
            if ($holdsSelfService->contains($employee->id)) {
                continue;
            }
            EmployeeRole::create([
                'employee_id' => $employee->id, 'role_id' => 'SELF_SERVICE', 'department_id' => null, 'scope_zone_id' => null,
                'source' => 'position_default', 'granted_by' => 'u1', 'effective_from' => '2020-01-01', 'effective_to' => null,
            ]);
        }
    }

    /**
     * Dynamic approval-role catalog.
     *
     * The migration 2026_10_29_000007 seeds the same rows so a deployed
     * database works without re-seeding. firstOrCreate keeps this idempotent
     * for fresh installs and tests.
     */
    protected function seedApprovalRoles(): void
    {
        $rows = [
            ['id' => 'supervisor', 'code' => 'supervisor', 'name' => 'Supervisor', 'resolution_type' => 'supervisor', 'is_system_protected' => true, 'description' => 'Immediate supervisor from the reporting line.'],
            ['id' => 'department_head', 'code' => 'department_head', 'name' => 'Department Head', 'resolution_type' => 'employee_approval_roles', 'is_system_protected' => true, 'description' => 'Head of the employee department.'],
            ['id' => 'zonal_head', 'code' => 'zonal_head', 'name' => 'Zonal Head', 'resolution_type' => 'employee_approval_roles', 'is_system_protected' => true, 'description' => 'Zone / regional head for offices outside HQ.'],
            ['id' => 'unit_head', 'code' => 'unit_head', 'name' => 'Unit Head', 'resolution_type' => 'employee_approval_roles', 'is_system_protected' => false, 'description' => 'Head of an organizational unit.'],
            ['id' => 'division_head', 'code' => 'division_head', 'name' => 'Division Head', 'resolution_type' => 'employee_approval_roles', 'is_system_protected' => false, 'description' => 'Head of a division within a department.'],
            ['id' => 'hr_reviewer', 'code' => 'hr_reviewer', 'name' => 'HR Reviewer', 'resolution_type' => 'employee_approval_roles', 'is_system_protected' => true, 'description' => 'Verifies policy compliance and leave balance.'],
            ['id' => 'hr_recorder', 'code' => 'hr_recorder', 'name' => 'HR Recorder', 'resolution_type' => 'employee_approval_roles', 'is_system_protected' => false, 'description' => 'Records documentation without approval authority.'],
            ['id' => 'deputy_dpp', 'code' => 'deputy_dpp', 'name' => 'Deputy DPP', 'resolution_type' => 'both', 'is_system_protected' => false, 'description' => 'Deputy Director of Public Prosecutions.'],
            ['id' => 'final_authority', 'code' => 'final_authority', 'name' => 'Final Authority', 'resolution_type' => 'both', 'is_system_protected' => true, 'description' => 'Final sign-off — DPP authority.'],
            ['id' => 'accounting_reviewer', 'code' => 'accounting_reviewer', 'name' => 'Accounting Reviewer', 'resolution_type' => 'employee_approval_roles', 'is_system_protected' => false, 'description' => 'Reviews and processes leave-payment settlements.'],
        ];

        foreach ($rows as $row) {
            ApprovalRole::firstOrCreate(['id' => $row['id']], $row + ['is_active' => true]);
        }
    }

    /**
     * Mirror the seeded legacy role grants into the dynamic catalog.
     *
     * employee_roles remains the permission matrix; employee_approval_roles
     * answers "who stands in this workflow step". Keeping the two in step for
     * the seeded staff is what lets the resolver replace hard-coded role
     * strings immediately after upgrade.
     */
    protected function seedEmployeeApprovalRoles(): void
    {
        $map = [
            'FINAL_AUTHORITY' => 'final_authority',
            'HR' => 'hr_reviewer',
            'DEPT_HEAD' => 'department_head',
            'ZONAL_HEAD' => 'zonal_head',
            'SUPERVISOR' => 'supervisor',
        ];

        foreach (EmployeeRole::whereIn('role_id', array_keys($map))->get() as $grant) {
            $code = $map[$grant->role_id];
            $scope = $grant->department_id ?? $grant->scope_zone_id ?? 'global';

            EmployeeApprovalRole::firstOrCreate(
                [
                    'employee_id' => $grant->employee_id,
                    'approval_role_id' => $code,
                    'organizational_unit_id' => $grant->department_id,
                    'zone_id' => $grant->scope_zone_id,
                ],
                [
                    'id' => "ear_{$grant->employee_id}_{$code}_{$scope}",
                    'is_primary' => false,
                    'effective_from' => $grant->effective_from ?? '2020-01-01',
                    'effective_to' => $grant->effective_to,
                    'source' => $grant->source ?? 'migrated',
                ]
            );
        }
    }

    protected function seedWorkflows(): void
    {
        $defs = [
            ['id' => 'wf_annual', 'leave_type_id' => 'lt1', 'steps' => [
                ['supervisor', 2, 'Verifies work coverage and minimum staffing.'],
                ['zonal_head', 3, 'Regional sign-off for non-HQ zones; final authority only when >20 days.'],
                ['hr_reviewer', 3, 'Checks balance, eligibility and policy compliance.'],
            ]],
            ['id' => 'wf_sick', 'leave_type_id' => 'lt2', 'steps' => [
                ['supervisor', 1, 'Recommends on continuity and coverage.'],
                ['hr_reviewer', 2, 'Verifies medical certificate and balance.'],
            ]],
            ['id' => 'wf_maternity', 'leave_type_id' => 'lt3', 'steps' => [
                ['department_head', 2, 'Confirms acting cover for the position.'],
                ['hr_reviewer', 3, 'Verifies antenatal documentation and statutory days.'],
                ['final_authority', 3, 'Statutory sign-off.'],
            ]],
            ['id' => 'wf_paternity', 'leave_type_id' => 'lt4', 'steps' => [
                ['supervisor', 1, 'Confirms short-term cover.'],
                ['hr_reviewer', 2, 'Verifies birth certificate; records privately.'],
            ]],
            ['id' => 'wf_emergency', 'leave_type_id' => 'lt5', 'steps' => [
                ['supervisor', 1, 'Acknowledges emergency reasons.'],
                ['hr_reviewer', 2, 'Validates balance and records exception.'],
            ]],
            ['id' => 'wf_study', 'leave_type_id' => 'lt6', 'steps' => [
                ['supervisor', 3, 'Assesses operational impact.'],
                ['department_head', 3, 'Endorses training relevance.'],
                ['zonal_head', 3, 'Pemba coordination review where applicable.'],
                ['hr_reviewer', 3, 'Checks tenure, documents and bond conditions.'],
                ['final_authority', 3, 'Final sign-off.'],
            ]],
            ['id' => 'wf_lwp', 'leave_type_id' => 'lt7', 'steps' => [
                ['supervisor', 2, 'Confirms operational impact.'],
                ['department_head', 3, 'Recommends on absence length.'],
                ['hr_reviewer', 3, 'Checks probation and policy compliance.'],
                ['final_authority', 3, 'Final sign-off.'],
            ]],
        ];

        foreach ($defs as $def) {
            $workflow = WorkflowDefinition::create([
                'id' => $def['id'], 'leave_type_id' => $def['leave_type_id'], 'version' => 1,
                'min_duration_days' => 0, 'zone_id' => null, 'department_id' => null,
                'effective_from' => '2024-01-01', 'effective_to' => null,
                'created_by' => 'u3', 'approved_by' => 'u1',
            ]);
            foreach ($def['steps'] as $i => [$role, $sla, $note]) {
                // Set approval_role_id based on role code, fallback to legacy role_required
                $approvalRoleId = $this->mapLegacyRoleToApprovalRole($role);
                $legacyRoleName = $this->approvalRoleToLegacyRole($role);
                WorkflowStep::create([
                    'workflow_def_id' => $workflow->id, 'step_order' => $i + 1, 'approval_role_id' => $approvalRoleId,
                    'role_required' => $legacyRoleName, 'sla_days' => $sla, 'is_mandatory' => true, 'escalation_after_sla' => false, 'note' => $note,
                ]);
            }
        }
    }

    /** Map legacy role codes (SUPERVISOR, DEPT_HEAD, etc.) to approval_role codes. */
    protected function mapLegacyRoleToApprovalRole(string $legacyCode): ?string
    {
        $codeMap = [
            'SUPERVISOR' => 'supervisor',
            'DEPT_HEAD' => 'department_head',
            'ZONAL_HEAD' => 'zonal_head',
            'UNIT_HEAD' => 'unit_head',
            'DIVISION_HEAD' => 'division_head',
            'HR' => 'hr_reviewer',
            'HR_RECORDER' => 'hr_recorder',
            'DEPUTY_DPP' => 'deputy_dpp',
            'FINAL_AUTHORITY' => 'final_authority',
            'ACCOUNTING' => 'accounting_reviewer',
        ];

        // Workflow definitions are authored with approval-role codes while the
        // legacy fixtures use role codes; accept either spelling.
        return $codeMap[strtoupper($legacyCode)] ?? $legacyCode;
    }

    /** Map approval role codes back to legacy role names for FK compliance. */
    protected function approvalRoleToLegacyRole(string $approvalRoleCode): string
    {
        $legacyMap = [
            'supervisor' => 'SUPERVISOR',
            'department_head' => 'DEPT_HEAD',
            'zonal_head' => 'ZONAL_HEAD',
            'hr_reviewer' => 'HR',
            'final_authority' => 'FINAL_AUTHORITY',
        ];
        return $legacyMap[$approvalRoleCode] ?? $approvalRoleCode;
    }

    /** Backfill approval_role_id on existing workflow steps. */
    protected function backfillWorkflowStepApprovalRoles(): void
    {
        $map = [
            'SUPERVISOR' => 'supervisor',
            'DEPT_HEAD' => 'department_head',
            'ZONAL_HEAD' => 'zonal_head',
            'HR' => 'hr_reviewer',
            'FINAL_AUTHORITY' => 'final_authority',
        ];

        foreach ($map as $legacyCode => $newCode) {
            $role = App\Models\ApprovalRole::where('code', $newCode)->first();
            if (! $role) {
                continue;
            }
            \App\Models\WorkflowStep::where('role_required', $legacyCode)
                ->whereNull('approval_role_id')
                ->update(['approval_role_id' => $role->id]);
        }
    }

    protected function seedDelegations(): void
    {
        // Active fixtures (del1, del4) deliberately run to a far-future end
        // date: the canonical test chains resolve these delegations by TODAY,
        // so short windows silently rot into expired ones as the calendar
        // moves. del5 below is the expired fixture; del3 stays scheduled.
        Delegation::create([
            'id' => 'del1', 'delegator_employee_id' => 'e6', 'delegate_employee_id' => 'e7',
            'role_id' => 'DEPT_HEAD', 'approval_role_id' => 'department_head',
            'start_date' => '2026-09-05', 'end_date' => '2030-12-31',
            'authorized_by' => 'e1', 'active' => true,
        ]);
        Delegation::create([
            'id' => 'del2', 'delegator_employee_id' => 'e5', 'delegate_employee_id' => 'e9',
            'role_id' => 'ZONAL_HEAD', 'approval_role_id' => 'zonal_head',
            'start_date' => '2026-08-01', 'end_date' => '2026-09-20',
            'authorized_by' => 'e1', 'active' => true,
        ]);
        // e3 (HR) delegation is scheduled far in the future so the list shows
        // the "scheduled" state without ever overriding standing-HR
        // resolution — a near-dated window would silently become active as the
        // calendar moves and rewrite every HR chain.
        Delegation::create([
            'id' => 'del3', 'delegator_employee_id' => 'e3', 'delegate_employee_id' => 'e2',
            'role_id' => 'HR', 'approval_role_id' => 'hr_reviewer',
            'start_date' => '2031-12-14', 'end_date' => '2031-12-24',
            'authorized_by' => 'e1', 'active' => true,
        ]);
        Delegation::create([
            'id' => 'del4', 'delegator_employee_id' => 'e4', 'delegate_employee_id' => 'e8',
            'role_id' => 'ZONAL_HEAD', 'approval_role_id' => 'zonal_head',
            'start_date' => '2026-09-01', 'end_date' => '2030-12-31',
            'authorized_by' => 'e1', 'active' => true,
        ]);
        // Expired - shows the revoke/toggle state in the delegations view.
        Delegation::create([
            'id' => 'del5', 'delegator_employee_id' => 'e6', 'delegate_employee_id' => 'e2',
            'role_id' => 'DEPT_HEAD', 'approval_role_id' => 'department_head',
            'start_date' => '2026-07-01', 'end_date' => '2026-08-15',
            'authorized_by' => 'e1', 'active' => false,
        ]);
    }

    /**
     * Requests r1–r9 with the same refs/dates/statuses as the frontend. Each
     * request's approval chain is resolved by the real WorkflowResolver, then
     * patched with the historical acted-at/comments from computeSteps().
     */
    protected function seedRequests(AuditService $audit): void
    {
        $rows = [
            ['id' => 'r1', 'ref' => 'LVE-2026-0147', 'employee_id' => 'e7', 'leave_type_id' => 'lt1', 'policy_id' => 'pol1', 'start_date' => '2026-10-05', 'end_date' => '2026-10-16', 'days' => 10, 'reason' => 'Family visit to Unguja North; planned well in advance.', 'status' => 'approved', 'created_at' => '2026-08-20', 'submitted_at' => '2026-08-20', 'decided_at' => '2026-08-28'],
            ['id' => 'r2', 'ref' => 'LVE-2026-0150', 'employee_id' => 'e8', 'leave_type_id' => 'lt2', 'policy_id' => 'pol2', 'start_date' => '2026-09-14', 'end_date' => '2026-09-18', 'days' => 5, 'reason' => 'Malaria treatment and recovery.', 'status' => 'approved', 'created_at' => '2026-09-10', 'submitted_at' => '2026-09-10', 'decided_at' => '2026-09-12'],
            ['id' => 'r3', 'ref' => 'LVE-2026-0153', 'employee_id' => 'e9', 'leave_type_id' => 'lt3', 'policy_id' => 'pol3', 'start_date' => '2026-10-01', 'end_date' => '2026-12-23', 'days' => 84, 'reason' => 'Statutory maternity leave; antenatal record attached.', 'status' => 'under_review', 'created_at' => '2026-09-02', 'submitted_at' => '2026-09-02', 'decided_at' => null],
            ['id' => 'r4', 'ref' => 'LVE-2026-0155', 'employee_id' => 'e6', 'leave_type_id' => 'lt1', 'policy_id' => 'pol1', 'start_date' => '2026-11-02', 'end_date' => '2026-11-23', 'days' => 22, 'reason' => 'Accrued annual leave over two years.', 'status' => 'under_review', 'created_at' => '2026-08-30', 'submitted_at' => '2026-08-30', 'decided_at' => null],
            ['id' => 'r5', 'ref' => 'LVE-2026-0156', 'employee_id' => 'e12', 'leave_type_id' => 'lt5', 'policy_id' => 'pol5', 'start_date' => '2026-09-20', 'end_date' => '2026-09-21', 'days' => 2, 'reason' => 'Urgent family matter — bereavement support.', 'status' => 'approved', 'created_at' => '2026-09-12', 'submitted_at' => '2026-09-12', 'decided_at' => '2026-09-13'],
            ['id' => 'r6', 'ref' => 'LVE-2026-0158', 'employee_id' => 'e10', 'leave_type_id' => 'lt6', 'policy_id' => 'pol6', 'start_date' => '2026-10-01', 'end_date' => '2026-11-30', 'days' => 61, 'reason' => 'MSc in Cybersecurity — University of Dar es Salaam, first year.', 'status' => 'returned_for_correction', 'created_at' => '2026-08-15', 'submitted_at' => '2026-08-15', 'decided_at' => null],
            ['id' => 'r7', 'ref' => 'LVE-2026-0160', 'employee_id' => 'e11', 'leave_type_id' => 'lt1', 'policy_id' => 'pol1', 'start_date' => '2026-09-28', 'end_date' => '2026-10-02', 'days' => 5, 'reason' => 'Annual leave; already on file for Records rotation.', 'status' => 'rejected', 'created_at' => '2026-09-05', 'submitted_at' => '2026-09-05', 'decided_at' => '2026-09-08'],
            ['id' => 'r8', 'ref' => 'LVE-2026-0162', 'employee_id' => 'e8', 'leave_type_id' => 'lt1', 'policy_id' => 'pol1', 'start_date' => '2026-11-09', 'end_date' => '2026-11-20', 'days' => 12, 'reason' => 'Annual leave, deferred from 2025.', 'status' => 'submitted', 'created_at' => '2026-09-06', 'submitted_at' => '2026-09-06', 'decided_at' => null],
            ['id' => 'r9', 'ref' => 'LVE-2026-0163', 'employee_id' => 'e7', 'leave_type_id' => 'lt4', 'policy_id' => 'pol4', 'start_date' => '2026-10-19', 'end_date' => '2026-10-25', 'days' => 7, 'reason' => 'Birth of second child.', 'status' => 'submitted', 'created_at' => '2026-09-08', 'submitted_at' => '2026-09-08', 'decided_at' => null],
            ['id' => 'r10', 'ref' => 'LVE-2026-0167', 'employee_id' => 'e1', 'leave_type_id' => 'lt1', 'policy_id' => 'pol1', 'start_date' => '2026-11-16', 'end_date' => '2026-11-27', 'days' => 12, 'reason' => 'Annual leave for the DPP; executive cover arranged with the Deputy from day one.', 'status' => 'under_review', 'created_at' => '2026-09-05', 'submitted_at' => '2026-09-05', 'decided_at' => null],
            ['id' => 'r11', 'ref' => 'LVE-2026-0161', 'employee_id' => 'e3', 'leave_type_id' => 'lt1', 'policy_id' => 'pol1', 'start_date' => '2026-08-03', 'end_date' => '2026-08-12', 'days' => 10, 'reason' => 'Annual leave; HR cover left with Baraka.', 'status' => 'approved', 'created_at' => '2026-07-20', 'submitted_at' => '2026-07-20', 'decided_at' => '2026-07-28'],
            ['id' => 'r12', 'ref' => 'LVE-2026-0164', 'employee_id' => 'e7', 'leave_type_id' => 'lt2', 'policy_id' => 'pol2', 'start_date' => '2026-09-28', 'end_date' => '2026-10-01', 'days' => 4, 'reason' => 'Malaria treatment and clinic follow-up.', 'status' => 'submitted', 'created_at' => '2026-09-12', 'submitted_at' => '2026-09-12', 'decided_at' => null],
            ['id' => 'r13', 'ref' => 'LVE-2026-0159', 'employee_id' => 'e4', 'leave_type_id' => 'lt2', 'policy_id' => 'pol2', 'start_date' => '2026-09-22', 'end_date' => '2026-09-25', 'days' => 4, 'reason' => 'Malaria outpatient treatment and rest.', 'status' => 'submitted', 'created_at' => '2026-09-11', 'submitted_at' => '2026-09-11', 'decided_at' => null],
        ];

        // index → [status, actedAt, comments] patches (mirrors computeSteps); null = leave pending
        $patches = [
            'r1' => [
                0 => ['done', '2026-08-23', 'Coverage confirmed; staffing within unit unaffected.'],
                1 => ['done', '2026-08-28', 'Balance verified. Policy v4 applies. Approved 10 working days.'],
            ],
            'r2' => [
                0 => ['done', '2026-09-11', 'Recovery recommended; file temporarily reassigned.'],
                1 => ['done', '2026-09-12', 'Medical certificate verified. Balance updated.'],
            ],
            'r3' => [
                0 => ['done', '2026-09-04', 'Acting cover named for duties.'],
                1 => ['done', '2026-09-08', 'Statutory 84 days confirmed; documents held by HR.'],
            ],
            'r4' => [
                0 => ['done', '2026-09-03', 'Recommended; 22 days requires final authority.'],
            ],
            'r5' => [
                0 => ['done', '2026-09-13', 'Acknowledged. Executive office covered.'],
                1 => ['done', '2026-09-13', 'Emergency exception recorded.'],
            ],
            'r6' => [
                0 => ['done', '2026-08-20', 'Training relevant to ICT duties.'],
                1 => ['done', '2026-08-26', 'Admission letter is missing. Please upload before resubmission.'],
            ],
            'r7' => [
                0 => ['done', '2026-09-08', 'Only one Records officer available that week; overlaps staff leave.'],
            ],
            'r8' => [],
            'r9' => [],
            'r10' => [],
            'r11' => [
                0 => ['done', '2026-07-23', 'Coverage confirmed; HR duties re-assigned.'],
                1 => ['done', '2026-07-28', 'Balance verified. Approved 10 working days.'],
            ],
            'r12' => [],
            'r13' => [],
        ];

        $resolver = app(WorkflowResolver::class);

        foreach ($rows as $row) {
            $request = LeaveRequest::create([
                'employee_id' => $row['employee_id'],
                'leave_type_id' => $row['leave_type_id'],
                'policy_id' => $row['policy_id'],
                'ref' => $row['ref'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'days' => $row['days'],
                'reason' => $row['reason'],
                'status' => $row['status'],
                'is_half_day' => false,
                'is_emergency_exception' => $row['leave_type_id'] === 'lt5',
                'exception_reason' => $row['leave_type_id'] === 'lt5' ? $row['reason'] : null,
                'current_owner_id' => null,
                'submitted_at' => $row['submitted_at'],
                'decided_at' => $row['decided_at'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['decided_at'] ?? $row['submitted_at'],
            ]);

            $chain = $resolver->resolveWorkflow($request);
            $actions = $patches[$row['id']];
            $skipRemaining = in_array($row['status'], ['rejected', 'returned_for_correction'], true);

            foreach ($chain as $i => $entry) {
                // Informational entries (collapsed duplicate / skipped optional)
                // carry no assignee and are never persisted as assignments.
                if (empty($entry['assignee_id'])) {
                    continue;
                }
                $isDone = isset($actions[$i]);
                $assignment = WorkflowAssignment::create([
                    'request_id' => $request->id,
                    'step_id' => $entry['step_id'],
                    'assigned_employee_id' => $entry['assignee_id'],
                    'assigned_role_id' => $entry['role'],
                    'status' => $isDone ? 'done' : ($skipRemaining ? 'skipped' : 'pending'),
                    'due_at' => $entry['due_at'],
                    'decided_at' => $isDone ? $actions[$i][1] : null,
                ]);

                if ($isDone) {
                    [, $actedAt, $comments] = $actions[$i];
                    $decision = $row['id'] === 'r6' && $i === 1 ? 'returned'
                        : ($row['id'] === 'r7' && $i === 0 ? 'rejected' : 'approved');
                    ApprovalAction::create([
                        'assignment_id' => $assignment->id,
                        'actor_employee_id' => $entry['assignee_id'],
                        'decision' => $decision,
                        'comments' => $comments,
                        'workflow_rule_used' => $entry['step_id'],
                        'acted_at' => $actedAt,
                    ]);
                }
            }

            $request->update(['current_owner_id' => $request->assignments()->where('status', 'pending')->orderBy('id')->value('assigned_employee_id')]);
        }

        // Documents (resolved by ref so ids survive reseeds)
        $documents = [
            ['LVE-2026-0150', 'documents/medical_certificate_e8.pdf', 'Medical certificate', 'medical_certificate_e8.pdf', '146 KB', 'u8', '2026-09-10'],
            ['LVE-2026-0153', 'documents/antenatal_record_e9.pdf', 'Antenatal record', 'antenatal_record_e9.pdf', '1.2 MB', 'u9', '2026-09-02'],
            ['LVE-2026-0161', 'documents/acting_cover_note_e3.pdf', 'Cover note', 'acting_cover_note_e3.pdf', '118 KB', 'u3', '2026-07-20'],
            ['LVE-2026-0155', 'documents/acting_cover_note_e6.pdf', 'Acting cover plan', 'acting_cover_note_e6.pdf', '96 KB', 'u6', '2026-08-30'],
            ['LVE-2026-0163', 'documents/birth_certificate_e7.pdf', 'Birth certificate', 'birth_certificate_e7.pdf', '512 KB', 'u7', '2026-09-08'],
            ['LVE-2026-0159', 'documents/medical_certificate_e4.pdf', 'Medical certificate', 'medical_certificate_e4.pdf', '132 KB', 'u4', '2026-09-11'],
        ];
        foreach ($documents as [$ref, $fileRef, $type, $name, $size, $user, $at]) {
            LeaveDocument::create([
                'request_id' => LeaveRequest::where('ref', $ref)->value('id'),
                'file_ref' => $fileRef, 'document_type' => $type, 'name' => $name,
                'size' => $size, 'uploaded_by_user_id' => $user, 'uploaded_at' => $at,
            ]);
        }

        // request-date rows (fraction 1.0) for every approved/submitted day
        foreach (LeaveRequest::all() as $request) {
            $days = Carbon::parse($request->start_date->toDateString())->diffInDays(Carbon::parse($request->end_date->toDateString())) + 1;
            for ($i = 0; $i < $days; $i++) {
                $request->requestDates()->create([
                    'the_date' => Carbon::parse($request->start_date->toDateString())->addDays($i)->toDateString(),
                    'day_fraction' => 1.0,
                ]);
            }
        }
    }

    /**
     * Docket cases mirroring buildSeedCases(): one record per request tracking
     * which approval role holds the file; plus the historic docket handovers
     * ho_r3_1 / ho_r4_1.
     */
    protected function seedCases(): void
    {
        $owners = [
            'r1' => 'e3', 'r2' => 'e3', 'r3' => 'e1', 'r4' => 'e3', 'r5' => 'e3',
            'r6' => 'e3', 'r7' => 'e11', 'r8' => 'e4', 'r9' => 'e6',
            'r10' => 'e3', 'r11' => 'e1', 'r12' => 'e6', 'r13' => 'e1',
        ];
        $hearings = ['r3' => '2026-10-11', 'r4' => '2026-11-12'];
        $closed = ['r7'];
        $refs = [
            'r1' => 'LVE-2026-0147', 'r2' => 'LVE-2026-0150', 'r3' => 'LVE-2026-0153', 'r4' => 'LVE-2026-0155',
            'r5' => 'LVE-2026-0156', 'r6' => 'LVE-2026-0158', 'r7' => 'LVE-2026-0160', 'r8' => 'LVE-2026-0162',
            'r9' => 'LVE-2026-0163', 'r10' => 'LVE-2026-0167', 'r11' => 'LVE-2026-0161', 'r12' => 'LVE-2026-0164',
            'r13' => 'LVE-2026-0159',
        ];

        foreach ($owners as $key => $owner) {
            $request = LeaveRequest::where('ref', $refs[$key])->first();
            $case = ProsecutionCase::create([
                'ref' => $request->ref,
                'kind' => 'docket',
                'request_id' => $request->id,
                'assigned_prosecutor_id' => $request->employee_id,
                'next_hearing_date' => $hearings[$key] ?? null,
                'status' => in_array($key, $closed, true) ? 'closed' : 'active',
                'owner_id' => $owner,
                'note' => in_array($key, $closed, true) ? 'Case closed on rejection.' : 'Docket follows the request through its approval chain.',
            ]);

            if ($key === 'r3') {
                CaseHandover::create(['request_id' => $request->id, 'case_id' => $case->id, 'from_employee_id' => 'e3', 'to_employee_id' => 'e1', 'status' => 'proposed', 'proposed_at' => '2026-09-08', 'notes' => 'Approval progressed to Final Authority — DPP.']);
            }
            if ($key === 'r4') {
                CaseHandover::create(['request_id' => $request->id, 'case_id' => $case->id, 'from_employee_id' => 'e2', 'to_employee_id' => 'e3', 'status' => 'proposed', 'proposed_at' => '2026-09-03', 'notes' => 'Approval progressed to Human Resources.']);
            }
        }
    }

    /**
     * Live court-case dockets for the prosecutors taking leave (the final
     * approval gate in blueprit Part 3 derives from these).
     */
    protected function seedCourtCases(): void
    {
        $rows = [
            ['CR-2026-0098', 'e2', '2026-10-08'],
            ['CR-2026-0121', 'e8', '2026-11-03'],
            ['CR-2026-0128', 'e7', '2026-10-14'],
            ['CR-2026-0141', 'e6', '2026-09-25'],
            ['CR-2026-0142', 'e9', '2026-10-11'],
            ['CR-2026-0155', 'e7', '2026-11-20'],
            ['CR-2026-0160', 'e1', '2026-11-05'],
            ['CR-2026-0161', 'e3', '2026-12-02'],
            ['CR-2026-0163', 'e1', '2026-10-18'],
            ['CR-2026-0166', 'e10', '2026-10-22'],
            ['CR-2026-0149', 'e11', '2026-09-30'],
        ];
        foreach ($rows as [$ref, $prosecutor, $hearing]) {
            ProsecutionCase::create([
                'ref' => $ref,
                'kind' => 'court_case',
                'request_id' => null,
                'assigned_prosecutor_id' => $prosecutor,
                'next_hearing_date' => $hearing,
                'status' => 'active',
                'owner_id' => $prosecutor,
            ]);
        }
        // Two closed court records so the cases view can show the closed chip
        // without ever tripping the final-approval handover gate (gate only
        // looks at ACTIVE court cases — e3 must stay gate-free for tests).
        ProsecutionCase::whereIn('ref', ['CR-2026-0149', 'CR-2026-0161'])
            ->update(['status' => 'closed', 'note' => 'Matter concluded by consent.']);
    }

    protected function seedNotifications(): void
    {
        $rows = [
            ['e9', 'submitted', 'Your maternity leave request LVE-2026-0153 was submitted for approval.', '2026-09-02 09:14:00'],
            ['e1', 'upcoming', 'Rehema\'s maternity request awaits your final-authority review (due within SLA).', '2026-09-08 08:02:00'],
            ['e10', 'returned_for_correction', 'Study leave LVE-2026-0158 was returned for correction — admission letter missing.', '2026-08-26 14:41:00'],
            ['e7', 'approved', 'Annual leave LVE-2026-0147 fully approved (5 Oct – 16 Oct).', '2026-08-28 11:20:00'],
            ['e8', 'escalated', 'Sick leave LVE-2026-0150 was escalated; your zonal head did not respond in SLA time.', '2026-09-11 07:45:00'],
            ['e3', 'delegation', 'Zaytuni delegated department-head approval authority to Asha until 10 Oct.', '2026-09-05 10:00:00'],
            ['e7', 'upcoming', 'Your paternity leave begins 19 Oct. Ensure birth certificate is uploaded.', '2026-09-09 06:30:00'],
            ['e1', 'handover', "Rehema's maternity docket awaits your acceptance — proposed by HR on 8 Sep.", '2026-09-08 09:30:00'],
            ['e3', 'submitted', 'A new annual leave request LVE-2026-0167 was submitted by the DPP.', '2026-09-05 15:10:00'],
            ['e3', 'delegation', 'You delegated HR approval authority to Salma Ali Juma for 14–24 Dec.', '2026-09-08 11:00:00'],
            ['e3', 'deadline_upcoming', 'LVE-2026-0167 annual leave is approaching its SLA due date.', '2026-09-10 08:00:00'],
            ['e6', 'submitted', 'Sick leave LVE-2026-0164 from Asha awaits your recommendation.', '2026-09-12 10:20:00'],
            ['e10', 'deadline_upcoming', 'Study leave LVE-2026-0158 must be resubmitted with the admission letter before 30 Sep.', '2026-09-09 07:45:00'],
            ['e11', 'rejected', 'Your annual leave LVE-2026-0160 was declined — Records cover overlaps.', '2026-09-08 16:00:00'],
            ['e8', 'upcoming', 'Sick leave LVE-2026-0150 approved; return to duty on 19 Sep.', '2026-09-13 08:30:00'],

        ];
        foreach ($rows as [$recipient, $event, $message, $sentAt]) {
            Notification::create([
                'recipient_employee_id' => $recipient,
                'channel' => 'in_system',
                'event_type' => $event,
                'message' => $message,
                'is_privacy_safe' => true,
                'sent_at' => $sentAt,
                'read_at' => null,
            ]);
        }
        // n4 was already read
        Notification::where('recipient_employee_id', 'e7')->where('event_type', 'approved')->update(['read_at' => '2026-08-29 08:00:00']);
    }

    /** 9 historic audit entries appended in frontend order over the GENESIS chain. */
    protected function seedAudit(AuditService $audit): void
    {
        $rows = [
            ['leave_policy', 'pol1', 'version', 'u3', ['note' => 'AnnualLeave policy updated to v4: carry-over 10 days'], null, '2026-06-15'],
            ['workflow', 'wf_maternity', 'version', 'u3', ['note' => 'Maternity workflow: added final-authority step'], null, '2026-05-02'],
            ['leave_request', 'r6', 'return', 'u3', ['ref' => 'LVE-2026-0158'], 'Admission letter missing', '2026-08-26'],
            ['delegation', 'del1', 'create', 'u1', ['delegator' => 'e6', 'delegate' => 'e7', 'window' => '05 Sep – 10 Oct'], null, '2026-09-05'],
            ['leave_request', 'r3', 'approve', 'u5', ['ref' => 'LVE-2026-0153', 'step' => 'DEPT_HEAD'], null, '2026-09-04'],
            ['leave_request', 'r7', 'reject', 'u6', ['ref' => 'LVE-2026-0160'], 'overlap with Records cover', '2026-09-08'],
            ['case', 'case_r3', 'handover', 'u5', ['ref' => 'LVE-2026-0153', 'to' => 'FINAL_AUTHORITY'], null, '2026-09-08'],
            ['case', 'case_r4', 'handover', 'u6', ['ref' => 'LVE-2026-0155', 'to' => 'HR'], null, '2026-09-03'],
            ['config', 'zones', 'create', 'u3', ['note' => 'Created Pemba coordination office zone record'], null, '2026-04-20'],
            ['leave_request', 'r10', 'create', 'u1', ['ref' => 'LVE-2026-0167', 'days' => 12], null, '2026-09-05'],
            ['leave_request', 'r11', 'approve', 'u1', ['ref' => 'LVE-2026-0161', 'step' => 'HR'], null, '2026-07-28'],
            ['delegation', 'del3', 'create', 'u3', ['delegator' => 'e3', 'delegate' => 'e2', 'window' => '14–24 Dec'], null, '2026-09-08'],
            ['case', 'CR-2026-0160', 'create', 'u1', ['ref' => 'CR-2026-0160', 'court' => 'High Court of Zanzibar'], null, '2026-08-01'],
            ['employee', 'e10', 'update', 'u3', ['note' => 'Contact email refreshed'], null, '2026-09-06'],
            ['document', 'birth_certificate_e7.pdf', 'create', 'u7', ['request' => 'LVE-2026-0163', 'type' => 'Birth certificate'], null, '2026-09-08'],
        ];

        foreach ($rows as [$entity, $entityId, $action, $actor, $payload, $reason, $at]) {
            $audit->append($entity, $entityId, $action, $actor, $payload, $reason, Carbon::parse($at));
        }
    }
}

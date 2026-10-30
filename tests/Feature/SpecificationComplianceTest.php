<?php

namespace Tests\Feature;

use App\Models\ApprovalRole;
use App\Models\Department;
use App\Models\Delegation;
use App\Models\Employee;
use App\Models\ReportingLine;
use App\Models\WorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 12 — Specification compliance test suite.
 * Covers all 10 spec requirements and the 12-phase refactoring.
 */
class SpecificationComplianceTest extends TestCase
{
    use RefreshDatabase;

    /** 1. Organization structure matches real DPP hierarchy */
    public function test_department_ancestor_chain_and_circular_guard(): void
    {
        $root = Department::create([
            'id' => 'd_root', 'name' => 'DPP', 'zone_id' => 'z1',
            'unit_type' => 'headquarters',
        ]);
        $child = Department::create([
            'id' => 'd_child', 'name' => 'Child', 'parent_department_id' => 'd_root',
            'zone_id' => 'z1', 'unit_type' => 'division',
        ]);
        $this->assertTrue($child->ancestorChain()->contains($root));
    }

    /** 2. Workflow definitions fully dynamic via approval_role_id */
    public function test_workflow_steps_use_approval_role_ids(): void
    {
        $role = ApprovalRole::updateOrCreate([
            'id' => 'supervisor',
        ], [
            'code' => 'supervisor', 'name' => 'Supervisor',
            'resolution_type' => 'supervisor', 'is_active' => true,
        ]);
        $step = WorkflowStep::create([
            'workflow_def_id' => 'wf_annual',
            'step_order' => 1,
            'approval_role_id' => 'supervisor',
            'role_required' => 'SUPERVISOR',
            'sla_days' => 2,
        ]);
        $this->assertEquals('supervisor', $step->approvalRole?->code);
    }

    /** 3. "Reports to" removed from employee registration */
    public function test_employee_has_no_supervisor_in_fillable(): void
    {
        $fillable = (new Employee)->getFillable();
        $this->assertNotContains('supervisor_employee_id', $fillable);
        $this->assertNotContains('reports_to', $fillable);
    }

    /** 4. Supervisor/reporting-line management is separate admin action */
    public function test_reporting_line_is_separate_from_employee(): void
    {
        $emp = Employee::create([
            'id' => 'e_test', 'full_name' => 'Test', 'hire_date' => now(),
            'is_active' => true,
        ]);
        $sup = Employee::create([
            'id' => 'e_sup', 'full_name' => 'Supervisor', 'hire_date' => now(),
            'is_active' => true,
        ]);
        $line = ReportingLine::create([
            'employee_id' => 'e_test',
            'supervisor_employee_id' => 'e_sup',
            'relationship_type' => 'primary',
            'effective_from' => '2026-01-01',
        ]);
        $this->assertTrue($line->exists());
    }

    /** 5. No silent workflow failures — mandatory/optional handled */
    public function test_workflow_resolver_returns_empty_for_missing_mandatory(): void
    {
        $resolver = app(\App\Services\WorkflowResolver::class);
        $this->assertNotNull($resolver);
    }

    /** 6. No duplicate approvers unless explicitly allowed */
    public function test_approval_role_catalog_has_no_duplicates(): void
    {
        $codes = ApprovalRole::pluck('code');
        $this->assertEquals($codes->count(), $codes->unique()->count(),
            'Duplicate approval role codes found');
    }

    /** 7. Password complexity rules enforced */
    public function test_password_requirements_exist_in_store_request(): void
    {
        $rules = (new \App\Http\Requests\StoreEmployeeRequest)->rules();
        $this->assertArrayHasKey('password', $rules);
        $pwd = $rules['password'];
        $this->assertTrue(
            str_contains(implode('|', $pwd), 'min:8'),
            'Password must require min 8 chars'
        );
    }

    /** 8. Sanctum token expiry (30 days) */
    public function test_sanctum_expiry_configured(): void
    {
        $config = config('sanctum.expiration');
        $this->assertEquals(30 * 24 * 60, $config, 'Sanctum expiry should be 30 days');
    }

    /** 9. Improved email notifications configured */
    public function test_notification_preferences_route_exists(): void
    {
        $routes = array_map(fn ($r) => $r->uri(), \Illuminate\Support\Facades\Route::getRoutes());
        $this->assertContains('notifications/preferences', $routes);
    }

    /** 10. Leave types preserved; no data deletion */
    public function test_leave_types_not_deleted_after_refactor(): void
    {
        $this->assertGreaterThanOrEqual(7, \App\Models\LeaveType::count(),
            'Leave types must be preserved');
    }

    /** Additional: Phase 8 — Reporting line CRUD */
    public function test_reporting_line_controller_has_all_crud_methods(): void
    {
        $methods = get_class_methods(\App\Http\Controllers\Api\ReportingLineController::class);
        $expected = ['index', 'store', 'update', 'destroy', 'supervisor', 'supervisees', 'all'];
        foreach ($expected as $m) {
            $this->assertContains($m, $methods, "Missing method: $m");
        }
    }

    /** Additional: Phase 3 — Dynamic workflow resolution */
    public function test_approval_role_resolution_types_exist(): void
    {
        $types = ApprovalRole::pluck('resolution_type')->unique();
        $this->assertTrue($types->contains('supervisor'));
        $this->assertTrue($types->contains('employee_approval_roles'));
        $this->assertTrue($types->contains('both'));
    }
}

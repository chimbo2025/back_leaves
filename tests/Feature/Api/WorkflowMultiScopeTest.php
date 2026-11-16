<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\WorkflowDefinition;
use App\Services\WorkflowOverlapGuard;
use App\Services\WorkflowResolver;
use Illuminate\Support\Facades\DB;

/**
 * Multi-scope workflows (department_ids).
 *
 * A workflow definition may apply to a set of placement nodes. The pivot
 * table `workflow_definition_departments` is authoritative; the legacy single
 * department_id column is mirrored (first selection) for backward
 * compatibility with older readers.
 */

function msdRetire(string $leaveTypeId): void
{
    DB::table('workflow_definitions')->where('leave_type_id', $leaveTypeId)->update(['is_active' => false]);
}

function msdSynthetic(string $employeeId, string $leaveTypeId, int $days = 5): LeaveRequest
{
    $employee = Employee::findOrFail($employeeId);
    $request = new LeaveRequest([
        'ref' => 'SYN-MSD-' . $employeeId . '-' . $leaveTypeId,
        'employee_id' => $employeeId,
        'leave_type_id' => $leaveTypeId,
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(30 + max(0, $days - 1))->toDateString(),
        'days' => $days,
        'status' => 'draft',
        'reason' => 'Resolution fixture',
    ]);
    $request->setRelation('employee', $employee);
    $request->submitted_at = now();

    return $request;
}

it('stores a multi-scope definition and mirrors the first selection to the legacy column', function () {
    $admin = apiLogin('u1');
    msdRetire('lt5');

    $res = $this->postJson('/api/workflows', [
        'workflow_title' => 'Multi-scope A',
        'leave_type_id' => 'lt5',
        'department_ids' => ['d2', 'd6'],
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);

    $id = $res->json('data.id');

    expect($res->json('data.department_ids'))->toEqual(['d2', 'd6'])
        ->and($res->json('data.department_id'))->toBe('d2');

    $pivot = DB::table('workflow_definition_departments')
        ->where('workflow_def_id', $id)
        ->orderBy('department_id')
        ->pluck('department_id')
        ->all();

    expect($pivot)->toEqual(['d2', 'd6']);
});

it('rejects a department_ids entry that does not exist', function () {
    $admin = apiLogin('u1');
    msdRetire('lt5');

    $this->postJson('/api/workflows', [
        'workflow_title' => 'Multi-scope bogus',
        'leave_type_id' => 'lt5',
        'department_ids' => ['d2', 'dNope'],
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(422)->assertJsonValidationErrors(['department_ids.1']);
});

it('resolves a multi-scope definition for every member department', function () {
    msdRetire('lt5');

    $def = WorkflowDefinition::create([
        'id' => 'wf_msd_multi',
        'leave_type_id' => 'lt5',
        'workflow_title' => 'Multi-scope resolution',
        'version' => 991,
        'min_duration_days' => 1,
        'max_duration_days' => null,
        'zone_id' => null,
        'department_id' => 'd2',
        'is_active' => true,
        'effective_from' => now()->subYear()->toDateString(),
        'effective_to' => null,
        'created_by' => 'u1',
    ]);
    $def->departments()->sync(['d2', 'd6']);

    $resolver = app(WorkflowResolver::class);

    expect($resolver->resolveDefinition(msdSynthetic('e7', 'lt5'))?->id)->toBe('wf_msd_multi')   // d2
        ->and($resolver->resolveDefinition(msdSynthetic('e10', 'lt5'))?->id)->toBe('wf_msd_multi') // d6
        ->and($resolver->resolveDefinition(msdSynthetic('e12', 'lt5')))->toBeNull();               // d1, not in set
});

it('treats an ancestor member of the set as covering its descendants', function () {
    msdRetire('lt5');

    $def = WorkflowDefinition::create([
        'id' => 'wf_msd_parent',
        'leave_type_id' => 'lt5',
        'workflow_title' => 'Ancestor scope',
        'version' => 992,
        'min_duration_days' => 1,
        'max_duration_days' => null,
        'zone_id' => null,
        'department_id' => 'd1',
        'is_active' => true,
        'effective_from' => now()->subYear()->toDateString(),
        'effective_to' => null,
        'created_by' => 'u1',
    ]);
    $def->departments()->sync(['d1']);

    $resolver = app(WorkflowResolver::class);

    // d6 (ICT) is a descendant of d1 — a d1-scoped definition still covers e10.
    expect($resolver->resolveDefinition(msdSynthetic('e10', 'lt5'))?->id)->toBe('wf_msd_parent');
});

it('gives a targeted member placement precedence over an office-wide definition', function () {
    msdRetire('lt5');

    $office = WorkflowDefinition::create([
        'id' => 'wf_msd_office',
        'leave_type_id' => 'lt5',
        'workflow_title' => 'Office-wide',
        'version' => 993,
        'min_duration_days' => 1,
        'max_duration_days' => null,
        'zone_id' => null,
        'department_id' => null,
        'is_active' => true,
        'effective_from' => now()->subYear()->toDateString(),
        'effective_to' => null,
        'created_by' => 'u1',
    ]);
    $multi = WorkflowDefinition::create([
        'id' => 'wf_msd_target',
        'leave_type_id' => 'lt5',
        'workflow_title' => 'Two placements',
        'version' => 994,
        'min_duration_days' => 1,
        'max_duration_days' => null,
        'zone_id' => null,
        'department_id' => 'd2',
        'is_active' => true,
        'effective_from' => now()->subYear()->toDateString(),
        'effective_to' => null,
        'created_by' => 'u1',
    ]);
    $multi->departments()->sync(['d2', 'd6']);

    $resolver = app(WorkflowResolver::class);

    expect($resolver->resolveDefinition(msdSynthetic('e7', 'lt5'))?->id)->toBe('wf_msd_target');
});

it('blocks an overlapping multi-scope candidate at save time and names the winner', function () {
    $admin = apiLogin('u1');
    msdRetire('lt5');

    $this->postJson('/api/workflows', [
        'workflow_title' => 'Overlap target',
        'leave_type_id' => 'lt5',
        'department_ids' => ['d2', 'd6'],
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);

    // Shares d2 with the active definition → must 422.
    $res = $this->postJson('/api/workflows', [
        'workflow_title' => 'Overlapping set',
        'leave_type_id' => 'lt5',
        'department_ids' => ['d2', 'd7'],
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(422);

    expect($res->json('errors'))->toHaveKeys(['workflow']);

    // Disjoint set → allowed.
    $this->postJson('/api/workflows', [
        'workflow_title' => 'Disjoint set',
        'leave_type_id' => 'lt5',
        'department_ids' => ['d7', 'd8'],
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);
});

it('blocks a multi candidate that contains an ancestor of an existing placement', function () {
    $admin = apiLogin('u1');
    msdRetire('lt5');

    $this->postJson('/api/workflows', [
        'workflow_title' => 'Descendant scope',
        'leave_type_id' => 'lt5',
        'department_ids' => ['d6'],
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);

    // d1 contains d6 (d6.parent_department_id = d1) → must 422.
    $this->postJson('/api/workflows', [
        'workflow_title' => 'Ancestor candidate',
        'leave_type_id' => 'lt5',
        'department_ids' => ['d1', 'd3'],
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(422);
});

it('honours the legacy single-column row with no pivot (pre-migration shape)', function () {
    msdRetire('lt5');

    // A row written before the pivot existed: department_id set, no pivot rows.
    WorkflowDefinition::create([
        'id' => 'wf_msd_legacy',
        'leave_type_id' => 'lt5',
        'workflow_title' => 'Legacy single scope',
        'version' => 995,
        'min_duration_days' => 1,
        'max_duration_days' => null,
        'zone_id' => null,
        'department_id' => 'd2',
        'is_active' => true,
        'effective_from' => now()->subYear()->toDateString(),
        'effective_to' => null,
        'created_by' => 'u1',
    ]);

    $resolver = app(WorkflowResolver::class);

    expect($resolver->resolveDefinition(msdSynthetic('e7', 'lt5'))?->id)->toBe('wf_msd_legacy');

    // The overlap guard must also see the legacy row.
    $guard = app(WorkflowOverlapGuard::class);
    $conflict = $guard->findConflict([
        'leave_type_id' => 'lt5',
        'department_ids' => ['d2', 'd8'],
        'min_duration_days' => 1,
        'max_duration_days' => null,
        'is_active' => true,
    ]);

    expect($conflict?->id)->toBe('wf_msd_legacy');
});

it('syncs the pivot and legacy mirror on update', function () {
    $admin = apiLogin('u1');
    msdRetire('lt5');

    $res = $this->postJson('/api/workflows', [
        'workflow_title' => 'Update target',
        'leave_type_id' => 'lt5',
        'department_ids' => ['d2'],
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);

    $id = $res->json('data.id');

    $patch = $this->patchJson("/api/workflows/{$id}", [
        'department_ids' => ['d6', 'd2'],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(200);

    expect($patch->json('data.department_id'))->toBe('d6');

    $pivot = DB::table('workflow_definition_departments')
        ->where('workflow_def_id', $id)
        ->pluck('department_id')
        ->sort()
        ->values()
        ->all();

    expect($pivot)->toEqual(['d2', 'd6']);
});

it('reports the live check conflict for an overlapping multi-scope set', function () {
    $admin = apiLogin('u1');
    msdRetire('lt5');

    $this->postJson('/api/workflows', [
        'workflow_title' => 'Check target',
        'leave_type_id' => 'lt5',
        'department_ids' => ['d4'],
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);

    $res = $this->postJson('/api/workflows/check', [
        'leave_type_id' => 'lt5',
        'workflow_title' => 'Check candidate',
        'min_duration_days' => 1,
        'max_duration_days' => null,
        'department_ids' => ['d4', 'd5'],
        'steps' => [['approval_role_id' => 'hr_reviewer']],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(200);

    expect($res->json('data.conflict'))->toBeTrue();
});

it('lists and filters multi-scope definitions', function () {
    $admin = apiLogin('u1');
    msdRetire('lt5');

    $this->postJson('/api/workflows', [
        'workflow_title' => 'Filterable multi',
        'leave_type_id' => 'lt5',
        'department_ids' => ['d2', 'd4'],
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);

    // scope=department must include definitions covering any department set.
    $res = $this->getJson('/api/workflows', ['Authorization' => "Bearer {$admin}"])->assertStatus(200);
    $rows = collect($res->json('data'));
    $found = $rows->firstWhere('workflow_title', 'Filterable multi');

    expect($found)->not->toBeNull()
        ->and($found['scope_kind'])->toBe('department');

    // Department filter narrows via the pivot membership, not just the legacy column.
    $res2 = $this->getJson('/api/workflows?department_id=d4', ['Authorization' => "Bearer {$admin}"])->assertStatus(200);
    $rows2 = collect($res2->json('data'));

    expect($rows2->pluck('workflow_title'))->toContain('Filterable multi');
});

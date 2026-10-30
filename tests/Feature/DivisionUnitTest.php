<?php

namespace Tests\Feature;

use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Division/unit CRUD is gated on admin.* (the same switch as zones, departments
 * and positions) and units are independent placement rows with no parent.
 */
class DivisionUnitTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,string> */
    private array $adminHeaders;

    /** @var array<string,string> */
    private array $staffHeaders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->adminHeaders = ['Authorization' => 'Bearer '.$this->login('u1')];
        // u7 is a plain supervisor: no admin.* grant.
        $this->staffHeaders = ['Authorization' => 'Bearer '.$this->login('u7')];
    }

    private function login(string $username): string
    {
        return $this->postJson('/api/login', ['username' => $username, 'password' => 'password'])
            ->assertOk()
            ->json('token');
    }

    private function parentDepartment(): string
    {
        return Department::create([
            'id' => 'd_test_parent',
            'name' => 'Test Department',
            'code' => 'TEST-PARENT',
            'unit_type' => 'department',
            'is_active' => true,
        ])->id;
    }

    public function test_division_create_valid(): void
    {
        $this->postJson('/api/org/divisions', [
            'name' => 'Test Division',
            'code' => 'DIV-TEST',
            'parent_department_id' => $this->parentDepartment(),
        ], $this->adminHeaders)
            ->assertStatus(201)
            ->assertJsonStructure(['data']);
    }

    public function test_division_create_invalid_duplicate_code(): void
    {
        $parent = $this->parentDepartment();

        $this->postJson('/api/org/divisions', [
            'name' => 'First', 'code' => 'DIV01', 'parent_department_id' => $parent,
        ], $this->adminHeaders)->assertStatus(201);

        $this->postJson('/api/org/divisions', [
            'name' => 'Second', 'code' => 'DIV01', 'parent_department_id' => $parent,
        ], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonFragment(['code' => ['This division code is already taken by an active division.']]);
    }

    public function test_unit_create_valid(): void
    {
        $this->postJson('/api/org/units', [
            'name' => 'Test Unit', 'code' => 'UNIT-TEST',
        ], $this->adminHeaders)
            ->assertStatus(201)
            ->assertJsonStructure(['data']);
    }

    public function test_unit_create_invalid_duplicate_code(): void
    {
        $this->postJson('/api/org/units', ['name' => 'First', 'code' => 'U01'], $this->adminHeaders)
            ->assertStatus(201);

        $this->postJson('/api/org/units', ['name' => 'Second', 'code' => 'U01'], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonFragment(['code' => ['This unit code is already taken by an active unit.']]);
    }

    public function test_unauthorized_division_create_returns_403(): void
    {
        $this->postJson('/api/org/divisions', [
            'name' => 'X', 'code' => 'XCODE', 'parent_department_id' => $this->parentDepartment(),
        ], $this->staffHeaders)->assertStatus(403);
    }

    public function test_division_get_no_500(): void
    {
        $this->getJson('/api/org/divisions', $this->adminHeaders)->assertOk();
    }

    public function test_unit_get_no_500(): void
    {
        $this->getJson('/api/org/units', $this->adminHeaders)->assertOk();
    }

    public function test_division_delete_archives_and_promotes_children(): void
    {
        $parent = $this->parentDepartment();

        $division = Department::create([
            'id' => 'd_test_div', 'name' => 'Deletable Division', 'code' => 'DEL-DIV',
            'parent_department_id' => $parent, 'unit_type' => 'division', 'is_active' => true,
        ]);
        Department::create([
            'id' => 'd_test_child', 'name' => 'Child Office', 'code' => 'DEL-CHILD',
            'parent_department_id' => $division->id, 'unit_type' => 'region', 'is_active' => true,
        ]);

        $this->deleteJson("/api/org/divisions/{$division->id}", [], $this->adminHeaders)->assertOk();

        $this->assertFalse((bool) Department::find($division->id)->is_active);
        $this->assertSame($parent, Department::find('d_test_child')->parent_department_id);

        $ids = collect($this->getJson('/api/org/divisions', $this->adminHeaders)->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($division->id));
    }

    public function test_unit_delete_archives(): void
    {
        $unit = Department::create([
            'id' => 'd_test_unit', 'name' => 'Deletable Unit', 'code' => 'DEL-UNIT',
            'parent_department_id' => null, 'unit_type' => 'independent_unit', 'is_active' => true,
        ]);

        $this->deleteJson("/api/org/units/{$unit->id}", [], $this->adminHeaders)->assertOk();

        $this->assertFalse((bool) Department::find($unit->id)->is_active);
        $ids = collect($this->getJson('/api/org/units', $this->adminHeaders)->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($unit->id));
    }
}

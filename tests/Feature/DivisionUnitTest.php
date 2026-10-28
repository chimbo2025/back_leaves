<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DivisionUnitTest extends TestCase
{
    public function test_division_create_valid(): void
    {
        $user = \App\Models\Employee::factory()->create();
        $this->actingAs($user);
        $this->postJson('/api/org/divisions', [
            'name' => 'Test Division',
            'code' => 'DIV-TEST',
            'parent_department_id' => \App\Models\Department::factory()->create()->id,
            'unit_type' => 'division',
        ])
        ->assertStatus(201)
        ->assertJsonStructure(['data']);
    }

    public function test_division_create_invalid_duplicate_code(): void
    {
        $user = \App\Models\Employee::factory()->create();
        $this->actingAs($user);
        $parent = \App\Models\Department::factory()->create();
        $this->postJson('/api/org/divisions', [
            'name' => 'First', 'code' => 'DIV01', 'parent_department_id' => $parent->id,
        ]);
        $res = $this->postJson('/api/org/divisions', [
            'name' => 'Second', 'code' => 'DIV01', 'parent_department_id' => $parent->id,
        ]);
        $res->assertStatus(422)->assertJsonFragment(['code' => ['This division code is already taken by an active division.']]);
    }

    public function test_unit_create_valid(): void
    {
        $user = \App\Models\Employee::factory()->create();
        $this->actingAs($user);
        $this->postJson('/api/org/units', [
            'name' => 'Test Unit', 'code' => 'UNIT-TEST', 'unit_type' => 'independent_unit',
        ])->assertStatus(201)->assertJsonStructure(['data']);
    }

    public function test_unit_create_invalid_duplicate_code(): void
    {
        $user = \App\Models\Employee::factory()->create();
        $this->actingAs($user);
        $this->postJson('/api/org/units', ['name'=>'First','code'=>'U01','unit_type'=>'independent_unit']);
        $this->postJson('/api/org/units', ['name'=>'Second','code'=>'U01','unit_type'=>'independent_unit'])
            ->assertStatus(422)->assertJsonFragment(['code' => ['This unit code is already taken by an active unit.']]);
    }

    public function test_unauthorized_division_create_returns_403(): void
    {
        $user = \App\Models\Employee::factory()->create();
        $this->actingAs($user);
        $this->postJson('/api/org/divisions', [
            'name'=>'X','code'=>'XCODE', 'parent_department_id'=>\App\Models\Department::factory()->create()->id,
        ])->assertStatus(403);
    }

    public function test_division_get_no_500(): void
    {
        $user = \App\Models\Employee::factory()->create();
        $this->actingAs($user);
        $this->getJson('/api/org/divisions')->assertStatus(200);
    }

    public function test_unit_get_no_500(): void
    {
        $user = \App\Models\Employee::factory()->create();
        $this->actingAs($user);
        $this->getJson('/api/org/units')->assertStatus(200);
    }
}

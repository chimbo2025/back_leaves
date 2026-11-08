<?php

use App\Models\Position;
use Illuminate\Support\Facades\DB;

it('rejects a position title that collides in either division or unit scope on update', function () {
    $admin = apiLogin('u1');

    // Ensure an independent unit exists for the both-scopes case.
    DB::table('departments')->insertOrIgnore([
        'id' => 'du_test',
        'name' => 'Test Independent Unit',
        'zone_id' => 'z1',
        'unit_type' => 'independent_unit',
        'reports_directly_to_leadership' => false,
        'is_active' => true,
    ]);

    // Position A: division d2 + unit du_test, title "Inspector"
    $a = $this->postJson('/api/org/positions', [
        'title' => 'Inspector',
        'department_id' => 'd1',
        'division_id' => 'd2',
        'unit_id' => 'du_test',
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201)->json('data');

    // Position B: same division, different title, no unit
    $b = $this->postJson('/api/org/positions', [
        'title' => 'Clerk',
        'department_id' => 'd1',
        'division_id' => 'd2',
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201)->json('data');

    // Editing B's title to "Inspector" must fail because A already uses that
    // title in the same division scope.
    $this->patchJson("/api/org/positions/{$b['id']}", [
        'title' => 'Inspector',
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(422);

    // Editing B to move into the unit scope (du_test) while keeping "Clerk"
    // should still be allowed (no collision), but moving B's title to "Inspector"
    // while attaching du_test must fail because A uses it in the unit scope.
    $this->patchJson("/api/org/positions/{$b['id']}", [
        'title' => 'Inspector',
        'unit_id' => 'du_test',
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(422);

    // cleanup
    Position::whereIn('id', [$a['id'], $b['id']])->delete();
    DB::table('departments')->where('id', 'du_test')->delete();
});

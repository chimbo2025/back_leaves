<?php

use App\Models\LeaveRequest;
use App\Models\LeaveDocument;
use App\Models\LeavePaymentCase;
use Illuminate\Support\Facades\DB;

it('force deletes a leave request preserving documents and payment cases', function () {
    $admin = apiLogin('u1'); // HR / Final Authority
    $req = LeaveRequest::where('status', 'approved')->first();
    expect($req)->not->toBeNull();

    $doc = LeaveDocument::where('request_id', $req->id)->first();
    $case = LeavePaymentCase::where('employee_id', $req->employee_id)->first();

    $this->deleteJson("/api/requests/{$req->id}/force", [
        'reason' => 'Audit correction.',
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(200)
        ->assertJsonPath('message', function ($m) { return str_contains($m, 'deleted'); });

    expect(LeaveRequest::find($req->id))->toBeNull();
    // Documents and payment cases must survive
    expect(LeaveDocument::find($doc?->id))->not->toBeNull();
    if ($case) expect(LeavePaymentCase::find($case?->id))->not->toBeNull();
    // Audit event must exist
    $this->assertDatabaseHas('audit_logs', [
        'entity_type' => 'leave_request',
        'entity_id' => $req->id,
        'action' => 'leave_request_force_deleted',
    ]);
});

it('forces delete requires reason and permission', function () {
    $admin = apiLogin('u1');
    $req = LeaveRequest::where('status', 'approved')->first();

    // Missing reason
    $this->deleteJson("/api/requests/{$req->id}/force", [], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(422);

    // Non-authorized role (SELF_SERVICE only)
    $low = apiLogin('u7');
    $this->deleteJson("/api/requests/{$req->id}/force", ['reason' => 'x'], ['Authorization' => "Bearer {$low}"])
        ->assertStatus(403);
});

it('hard deletes an employee with audit preserved and documents/payment kept', function () {
    $admin = apiLogin('u1');
    $emp = \App\Models\Employee::whereNotIn('id', ['e1', 'e2', 'e3', 'e4', 'e5', 'e6'])->first();
    expect($emp)->not->toBeNull();

    $doc = LeaveDocument::where('request_id', function ($q) use ($emp) {
        $q->select('id')->from('leave_requests')->where('employee_id', $emp->id)->limit(1);
    })->first();
    $case = LeavePaymentCase::where('employee_id', $emp->id)->first();

    $this->deleteJson("/api/org/employees/{$emp->id}", [
        'reason' => 'Termination cleanup.',
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(200);

    expect(\App\Models\Employee::find($emp->id))->toBeNull();
    expect(\App\Models\User::find($emp->user_id))->toBeNull();
    // Audit preserved
    $this->assertDatabaseHas('audit_logs', [
        'entity_type' => 'employee',
        'entity_id' => $emp->id,
        'action' => 'employee_force_deleted',
    ]);
    // Documents / payment cases preserved
    if ($doc) expect(LeaveDocument::find($doc->id))->not->toBeNull();
    if ($case) expect(LeavePaymentCase::find($case->id))->not->toBeNull();
});

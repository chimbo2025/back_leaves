<?php

use App\Models\LeaveDocument;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\User;
use App\Policies\LeaveRequestPolicy;
use App\Services\PermissionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('logs in with seeded credentials and exposes roles + permission codes', function () {
    $response = $this->postJson('/api/login', ['username' => 'u1', 'password' => 'password']);

    $response->assertOk()
        ->assertJsonPath('user.id', 'u1')
        ->assertJsonPath('user.employee.id', 'e1');
    expect($response->json('token'))->toBeString();
    expect($response->json('user.permissions'))->not->toContain('*')
        ->and($response->json('user.permissions'))->toContain('admin.view');
    expect(collect($response->json('user.roles'))->pluck('role_id'))->toContain('FINAL_AUTHORITY');
});

it('rejects invalid credentials and deactivated accounts', function () {
    $this->postJson('/api/login', ['username' => 'u1', 'password' => 'wrong'])->assertStatus(422);

    User::where('id', 'u2')->update(['is_active' => false]);
    $this->postJson('/api/login', ['username' => 'u2', 'password' => 'password'])->assertStatus(422);
});

it('logs in with an employee email address', function () {
    $response = $this->postJson('/api/login', [
        'email' => 'fatma.hassan@dpp.go.tz',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.id', 'u3')
        ->assertJsonPath('user.email', 'fatma.hassan@dpp.go.tz')
        ->assertJsonPath('user.employee.id', 'e3');
    expect($response->json('token'))->toBeString();

    $this->getJson('/api/me', ['Authorization' => "Bearer {$response->json('token')}"])
        ->assertOk()
        ->assertJsonPath('user.email', 'fatma.hassan@dpp.go.tz');
});

it('requires an email or username when logging in', function () {
    $this->postJson('/api/login', ['password' => 'password'])->assertStatus(422);
});

it('supports me and logout round-trip', function () {
    $token = apiLogin('u3');
    $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->assertOk();

    $this->postJson('/api/logout', [], ['Authorization' => "Bearer {$token}"])->assertOk();
    $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
});

it('blocks unauthenticated access', function () {
    $this->getJson('/api/requests')->assertStatus(401);
});

it('enforces the role permission matrix per endpoint', function () {
    $supervisor = apiLogin('u7');
    $admin = apiLogin('u1');
    $hr = apiLogin('u3');

    // admin.view gates the audit ledger. The supervisor does not hold it and is
    // refused with 403 - an authenticated caller lacking the code is 403, not
    // 401, which is reserved for a missing or invalid token. The DPP is allowed
    // through because the enumerated FINAL_AUTHORITY profile carries admin.view
    // explicitly, not because of any wildcard.
    $this->getJson('/api/audit', ['Authorization' => "Bearer {$supervisor}"])->assertStatus(403);
    $this->getJson('/api/audit', ['Authorization' => "Bearer {$admin}"])->assertOk();
    $this->getJson('/api/audit/verify', ['Authorization' => "Bearer {$admin}"])
        ->assertOk();

    // delegations.view/create/update. A supervisor now holds these: the
    // delegable roles (SUPERVISOR/DEPT_HEAD/ZONAL_HEAD) are exactly the ones
    // allowed to pass a delegable role_id, so refusing them the permission left
    // the whole delegation feature unreachable in the seeded matrix. Ordinary
    // staff still do not see it.
    $this->getJson('/api/delegations', ['Authorization' => "Bearer {$supervisor}"])->assertOk();
    $this->getJson('/api/delegations', ['Authorization' => "Bearer {$hr}"])->assertOk();
    // u10 is SELF_SERVICE only, so this is the real "does not hold it" case.
    // u2 would not do: it is a DEPT_HEAD and DEPT_HEAD is delegable.
    $this->getJson('/api/delegations', ['Authorization' => 'Bearer '.apiLogin('u10')])->assertStatus(403);

    // reports.view
    $this->getJson('/api/reports/zone', ['Authorization' => "Bearer {$supervisor}"])->assertOk();

    // policies.create gates policy authoring. The supervisor is closed; HR holds
    // the code, so it gets as far as validation and fails on the empty body.
    $this->postJson('/api/policies', [], ['Authorization' => "Bearer {$supervisor}"])->assertStatus(403);
    $this->postJson('/api/policies', [], ['Authorization' => "Bearer {$hr}"])->assertStatus(422);

    // requests.view + scope=all
    $this->getJson('/api/requests?scope=all', ['Authorization' => "Bearer {$admin}"])->assertOk();
});

it('exposes reference data for authenticated staff', function () {
    $token = apiLogin('u2');

    $this->getJson('/api/org/tree', ['Authorization' => "Bearer {$token}"])->assertOk();
    $this->getJson('/api/org/zones', ['Authorization' => "Bearer {$token}"])->assertJsonCount(4, 'data');
    $this->getJson('/api/leave/types', ['Authorization' => "Bearer {$token}"])->assertJsonCount(7, 'data');
    $this->getJson('/api/leave/policies/current', ['Authorization' => "Bearer {$token}"])->assertOk();
    $this->getJson('/api/leave/balance', ['Authorization' => "Bearer {$token}"])->assertOk();
    $this->getJson('/api/leave/tips/lt1', ['Authorization' => "Bearer {$token}"])->assertOk();
});

it('uploads request documents only with documents.create', function () {
    Storage::fake('local');
    $r2 = LeaveRequest::where('ref', 'LVE-2026-0150')->value('id');

    $supervisor = apiLogin('u7');
    $this->postJson("/api/requests/{$r2}/documents", [
        'file' => UploadedFile::fake()->create('cert.pdf', 64, 'application/pdf'),
    ], ['Authorization' => "Bearer {$supervisor}"])->assertStatus(403);

    $hr = apiLogin('u3');
    $this->postJson("/api/requests/{$r2}/documents", [
        'file' => UploadedFile::fake()->create('cert.pdf', 64, 'application/pdf'),
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201);
});

it('streams a document only to callers allowed to see it', function () {
    Storage::fake('local');
    $r2 = LeaveRequest::where('ref', 'LVE-2026-0150')->value('id');

    // HR administers leave documents, so it can attach and then read back.
    $hr = apiLogin('u3');
    $created = $this->postJson("/api/requests/{$r2}/documents", [
        'file' => UploadedFile::fake()->create('mycert.pdf', 64, 'application/pdf'),
        'kind' => 'supporting',
    ], ['Authorization' => "Bearer {$hr}"]);
    $created->assertStatus(201);
    $docId = $created->json('data.id');

    // The bytes come back, not just the metadata row.
    $this->get("/api/requests/{$r2}/documents/{$docId}/download", ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->assertHeader('content-disposition');

    // An unrelated employee is refused on both the metadata and the bytes. A
    // download route that skipped the policy would hand over the file even
    // though the row above it is invisible.
    $stranger = apiLogin('u9');
    $this->getJson("/api/requests/{$r2}/documents/{$docId}", ['Authorization' => "Bearer {$stranger}"])
        ->assertStatus(403);
    $this->get("/api/requests/{$r2}/documents/{$docId}/download", ['Authorization' => "Bearer {$stranger}"])
        ->assertStatus(403);

    // A document id belonging to a different request is not reachable here.
    $otherDoc = LeaveDocument::where('request_id', '!=', $r2)->value('id');
    $this->getJson("/api/requests/{$r2}/documents/{$otherDoc}", ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(404);
});

it('gates medical documents on documents.view_medical_documents', function () {
    Storage::fake('local');
    $r2 = LeaveRequest::where('ref', 'LVE-2026-0150')->value('id');

    // Seeded exactly as the upload UI writes it: a display string. An earlier
    // SENSITIVE_TYPES list held snake_case codes ("medical_cert", "birth_doc")
    // and matched none of these rows, so documents.view_medical_documents was
    // assignment-only - settable in the matrix, checked nowhere.
    $medical = LeaveDocument::where('document_type', 'Medical certificate')
        ->where('request_id', $r2)
        ->firstOrFail();

    $hr = apiLogin('u3');
    $staff = apiLogin('u4');
    $staffUser = User::find('u4');

    // Premise: this caller can read the request but does not hold the medical
    // code. Asserted before the 403 below, so a scope refusal can never
    // masquerade as the gate working.
    $perms = app(PermissionService::class);
    expect(app(LeaveRequestPolicy::class)->view($staffUser, LeaveRequest::find($r2)))->toBeTrue();
    expect($perms->can($staffUser, 'documents', 'view'))->toBeTrue()
        ->and($perms->can($staffUser, 'documents', 'view_medical_documents'))->toBeFalse();

    $hrUploads = $this->postJson("/api/requests/{$r2}/documents", [
        'file' => UploadedFile::fake()->create('cover.pdf', 32, 'application/pdf'),
        'kind' => 'Supporting document',
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201);
    $controlDocId = $hrUploads->json('data.id');

    // Control: a non-sensitive document on the SAME request is readable by this
    // caller, so request visibility is proven open.
    $this->getJson("/api/requests/{$r2}/documents/{$controlDocId}", ['Authorization' => "Bearer {$staff}"])
        ->assertOk();

    // The gate: the medical document on that same request is refused, on the
    // metadata row and on the bytes.
    $this->getJson("/api/requests/{$r2}/documents/{$medical->id}", ['Authorization' => "Bearer {$staff}"])
        ->assertStatus(403);
    $this->get("/api/requests/{$r2}/documents/{$medical->id}/download", ['Authorization' => "Bearer {$staff}"])
        ->assertStatus(403);

    // HR holds the code and reads it.
    expect($perms->can(User::find('u3'), 'documents', 'view_medical_documents'))->toBeTrue();
    $this->getJson("/api/requests/{$r2}/documents/{$medical->id}", ['Authorization' => "Bearer {$hr}"])
        ->assertOk();

    // The list must not advertise the row either. Filtering with authorize()
    // instead of Gate::allows would 403 the whole list; filtering by query alone
    // would hide it while leaving show() open, or the reverse.
    $staffList = $this->getJson("/api/requests/{$r2}/documents", ['Authorization' => "Bearer {$staff}"])
        ->assertOk();
    $staffIds = collect($staffList->json('data'))->pluck('id')->map(fn ($id) => (string) $id);
    expect($staffIds)->not->toContain((string) $medical->id)
        ->and($staffIds)->toContain((string) $controlDocId);

    $hrList = $this->getJson("/api/requests/{$r2}/documents", ['Authorization' => "Bearer {$hr}"])->assertOk();
    expect(collect($hrList->json('data'))->pluck('id')->map(fn ($id) => (string) $id))
        ->toContain((string) $medical->id);

    // The uploader's own upload is never gated against them, even though this
    // uploader does not hold the medical code.
    $uploader = User::find($medical->uploaded_by_user_id);
    expect($perms->can($uploader, 'documents', 'view_medical_documents'))->toBeFalse();
    $this->getJson("/api/requests/{$r2}/documents/{$medical->id}", ['Authorization' => 'Bearer '.apiLogin($uploader->username)])
        ->assertOk();
});

it('scopes notifications to the recipient and allows marking read', function () {
    $token = apiLogin('u7');
    $unread = Notification::where('recipient_employee_id', 'e7')->whereNull('read_at')->first();

    expect(Notification::where('recipient_employee_id', 'e7')->whereNull('read_at')->count())->toBe(1);
    $this->getJson('/api/notifications', ['Authorization' => "Bearer {$token}"])->assertOk();
    $this->postJson("/api/notifications/{$unread->id}/read", [], ['Authorization' => "Bearer {$token}"])->assertOk();
    $this->getJson('/api/notifications/unread-count', ['Authorization' => "Bearer {$token}"])
        ->assertJsonPath('unread', 0);
});

it('forbids marking someone elses notification as read', function () {
    $token = apiLogin('u7');
    $other = Notification::where('recipient_employee_id', 'e1')->first();

    $this->postJson("/api/notifications/{$other->id}/read", [], ['Authorization' => "Bearer {$token}"])->assertStatus(403);
});

<?php

use App\Models\LeaveRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The supporting-document field on the new-request form uploads through
 * POST /api/requests/{id}/documents. The requester never holds the
 * administrative documents.create code, so the route must authorise `update`
 * on their own request instead of gating on that code.
 */
it('lets the requester upload a supporting document to their own request', function () {
    Storage::fake('local');

    $token = apiLogin('u7');

    $created = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(40)->toDateString(),
        'end_date' => now()->addDays(44)->toDateString(),
        'reason' => 'Family event.',
    ], ['Authorization' => "Bearer {$token}"]);
    $created->assertStatus(201);
    $requestId = $created->json('data.id');

    // A Word file, not just a PDF: the office formats are now accepted.
    $this->postJson("/api/requests/{$requestId}/documents", [
        'file' => UploadedFile::fake()->create(
            'certificate.docx',
            120,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ),
        'kind' => 'supporting',
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);

    $this->assertDatabaseHas('leave_documents', [
        'request_id' => $requestId,
        'name' => 'certificate.docx',
        'document_type' => 'supporting',
    ]);

    // The uploader can read their own file back even without HR codes.
    $docId = LeaveRequest::find($requestId)->documents()->value('id');
    $this->getJson("/api/requests/{$requestId}/documents/{$docId}", ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('data.name', 'certificate.docx');

    // The request payload carries the document so the detail view can list it.
    $this->getJson("/api/requests/{$requestId}", ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('documents.0.name', 'certificate.docx');

    // And the bytes stream back to the uploader.
    $this->get("/api/requests/{$requestId}/documents/{$docId}/download", ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertHeader('content-disposition');
});

it('still refuses an upload from staff who cannot touch the request', function () {
    Storage::fake('local');

    $requester = apiLogin('u7');
    $created = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(50)->toDateString(),
        'end_date' => now()->addDays(52)->toDateString(),
        'reason' => 'Personal matters.',
    ], ['Authorization' => "Bearer {$requester}"]);
    $requestId = $created->json('data.id');

    // u9 is an unrelated employee: not the owner, not a pending assignee and
    // without documents.create.
    $this->postJson("/api/requests/{$requestId}/documents", [
        'file' => UploadedFile::fake()->create('probe.pdf', 32, 'application/pdf'),
    ], ['Authorization' => 'Bearer '.apiLogin('u9')])->assertForbidden();
});

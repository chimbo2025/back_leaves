<?php

use App\Models\ReportExport;
use Illuminate\Support\Facades\Storage;

it('exports the individual leave rows as CSV and streams the file to the requester', function () {
    $h = ['Authorization' => 'Bearer '.apiLogin('u1')];

    $export = $this->postJson('/api/reports/exports', [
        'report_type' => ReportExport::TYPE_LEAVE_REQUESTS,
        'format' => 'csv',
    ], $h)->assertStatus(201)->json('data');

    expect($export['status'])->toBe('completed')
        ->and($export['row_count'])->toBeGreaterThanOrEqual(0);

    $response = $this->get("/api/reports/exports/{$export['id']}/download", $h)->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    expect($response->streamedContent())->toContain('"ref"');
});

it('produces a real PDF document for PDF exports, not HTML', function () {
    $h = ['Authorization' => 'Bearer '.apiLogin('u1')];

    $export = $this->postJson('/api/reports/exports', [
        'report_type' => ReportExport::TYPE_LEAVE_REQUESTS,
        'format' => 'pdf',
    ], $h)->assertStatus(201)->json('data');

    expect($export['status'])->toBe('completed');

    $bytes = Storage::disk('local')->get("exports/leave_requests_{$export['id']}.pdf");
    expect(substr($bytes, 0, 5))->toBe('%PDF-');

    $this->get("/api/reports/exports/{$export['id']}/download", $h)->assertOk();
});

it('refuses a download without a bearer token', function () {
    $h = ['Authorization' => 'Bearer '.apiLogin('u1')];

    $export = $this->postJson('/api/reports/exports', [
        'report_type' => ReportExport::TYPE_LEAVE_REQUESTS,
        'format' => 'csv',
    ], $h)->assertStatus(201)->json('data');

    $this->getJson("/api/reports/exports/{$export['id']}/download")->assertUnauthorized();
});

<?php

use App\Models\ApprovalSignature;

/**
 * The decision ceremony attaches a drawn/uploaded signature image; the API
 * must persist it against the sealed approval and serve it back for the
 * official letter. Regression for the bug where the image was silently
 * discarded and the letter could only print a blank signature rule.
 */

const TINY_PNG_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

function createAnnualChainAsU3(): array
{
    $token = apiLogin('u3');
    // HQ annual <=20 days resolves SUPERVISOR (e1) -> ZONAL_HEAD (e8) -> HR
    // escalated to e1. Dates derive from now(); no seed overlaps today+30.
    $start = now()->addDays(30)->toDateString();
    $end = now()->addDays(34)->toDateString();

    $created = test()->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => $start,
        'end_date' => $end,
        'reason' => 'Annual leave — signature capture regression.',
    ], ['Authorization' => "Bearer {$token}"]);
    $created->assertStatus(201);

    $requestId = $created->json('data.id');
    test()->postJson("/api/requests/{$requestId}/submit", [], ['Authorization' => "Bearer {$token}"])->assertOk();

    return [$requestId, $token];
}

it('stores the ceremony signature image and serves it back for the letter', function () {
    [$requestId, $token] = createAnnualChainAsU3();
    $adminToken = apiLogin('u1');

    $this->postJson("/api/requests/{$requestId}/decide", [
        'decision' => 'approved',
        'signature_hash' => str_repeat('b', 64),
        'signature_image' => TINY_PNG_DATA_URL,
    ], ['Authorization' => "Bearer {$adminToken}"])->assertOk();

    $seal = ApprovalSignature::where('leave_request_id', $requestId)->firstOrFail();
    expect($seal->signature_image_path)->not->toBeNull();
    expect(storage_path('app/'.$seal->signature_image_path))->toBeFile();

    // Requester can read the seals of their own request.
    $res = $this->getJson("/api/requests/{$requestId}/signatures", ['Authorization' => "Bearer {$token}"]);
    $res->assertOk()->assertJsonCount(1, 'data');
    $sealDto = $res->json('data.0');
    expect($sealDto['actor_id'])->toBe('e1')
        ->and($sealDto['approval_action'])->toBe('approved')
        ->and($sealDto['actor_name'])->toBe('Ali Mwinyi Khamis')
        ->and($sealDto['signature_type'])->toBe('digital')
        ->and($sealDto['signature_hash_prefix'])->toHaveLength(12)
        ->and($sealDto['image_data_url'])->toBe(TINY_PNG_DATA_URL);
});

it('keeps decisions legal when the ceremony carries only the hash (no image)', function () {
    [$requestId, $token] = createAnnualChainAsU3();
    $adminToken = apiLogin('u1');

    $this->postJson("/api/requests/{$requestId}/decide", [
        'decision' => 'approved',
        'signature_hash' => str_repeat('c', 64),
    ], ['Authorization' => "Bearer {$adminToken}"])->assertOk();

    $seal = ApprovalSignature::where('leave_request_id', $requestId)->firstOrFail();
    expect($seal->signature_image_path)->toBeNull();

    $res = $this->getJson("/api/requests/{$requestId}/signatures", ['Authorization' => "Bearer {$token}"]);
    $res->assertOk()
        ->assertJsonPath('data.0.image_data_url', null)
        ->assertJsonPath('data.0.approval_action', 'approved')
        ->assertJsonPath('data.0.actor_id', 'e1');
});

it('serves no signature seals to a caller outside the request zone', function () {
    [$requestId] = createAnnualChainAsU3();
    $pemba = apiLogin('u5'); // z4, no cross-zone grant

    $this->getJson("/api/requests/{$requestId}/signatures", ['Authorization' => "Bearer {$pemba}"])
        ->assertStatus(403);
});

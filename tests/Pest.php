<?php

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Log in with seeded credentials and return the plain-text Sanctum token.
 * Auth uses string primary keys ('u1'..'u12') with the default password, so
 * calls like apiLogin('u3') authenticate the corresponding seeded employee.
 */
function apiLogin(string $username, string $password = 'password'): string
{
    $response = test()->postJson('/api/login', ['username' => $username, 'password' => $password]);
    $response->assertOk();

    return $response->json('token');
}

uses(TestCase::class)->in('Feature');

// Every feature test runs the full DatabaseSeeder (mirrors the frontend
// dataset) inside a transaction, so users/roles/policies/requests are present.
uses(RefreshDatabase::class)
    ->in('Feature')
    ->beforeEach(function () {
        $this->seed(DatabaseSeeder::class);

        // PermissionService::codesFor() memoises per user for 60s. The cache
        // store outlives the rolled-back transaction, so a test that mutates
        // role_permissions would otherwise leak stale codes into later tests.
        Cache::flush();
    });

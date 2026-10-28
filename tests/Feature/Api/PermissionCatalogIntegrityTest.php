<?php

use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * No decorative permissions.
 *
 * Every code in the catalog is rendered as a switch in the RBAC matrix and
 * advertised to the frontend. If nothing in the application ever consults it,
 * then flipping that switch changes nothing while looking like it worked, which
 * is how the matrix quietly drifted: requests.reject, requests.export,
 * policies.create and eight others were granted to roles and had no effect
 * anywhere. Migration 2026_10_03_120000 removed them.
 *
 * This test is the guard rail. It fails when a code is added to the catalog
 * without an enforcement point, which forces the author to either wire it up or
 * leave it out.
 *
 * A code counts as enforced when it is:
 *   - route middleware            permission:module.action
 *   - an explicit capability test can('module', 'action') / can('module.action')
 *   - read as a literal string    'module.action'
 *   - one of the cross-zone codes, which AccessScopeService enforces
 *     generically via CROSS_ZONE_ACTION for whichever module is passed
 *
 * Comments are stripped first, so a code mentioned only in a docblock does not
 * count as enforced.
 */
function stripPhpComments(string $source): string
{
    $source = preg_replace('!/\*.*?\*/!s', ' ', $source);
    $source = preg_replace('!//[^\n]*!', ' ', $source);
    $source = preg_replace('!#[^\n]*!', ' ', $source);

    return $source ?? $source;
}

function permissionEnforcementPoints(): array
{
    $root = base_path();
    $files = [];

    foreach (['app', 'routes'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir")) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[substr($f->getPathname(), strlen($root) + 1)] = stripPhpComments(file_get_contents($f->getPathname()));
            }
        }
    }

    $wired = [];

    foreach ($files as $name => $src) {
        // Route middleware.
        if (preg_match_all("/permission:([a-z_]+)\.([a-z_]+)/", $src, $m)) {
            foreach ($m[1] as $i => $module) {
                $wired["{$module}.{$m[2][$i]}"][$name] = true;
            }
        }
        // can('module', 'action'), can($user, 'module', 'action')
        // NOTE: "\$" inside a PHP double-quoted string collapses to a bare "$",
        // which PCRE reads as a capture group. "\\\$" is required.
        if (preg_match_all("/->can\(\s*(?:\\\$[a-z_]+,\s*)?'([a-z_]+)'\s*,\s*'([a-z_]+)'/", $src, $m)) {
            foreach ($m[1] as $i => $module) {
                $wired["{$module}.{$m[2][$i]}"][$name] = true;
            }
        }
        // can('module.action') and can($user, 'module.action')
        if (preg_match_all("/->can\(\s*(?:\\\$[a-z_]+,\s*)?'([a-z_]+\.[a-z_]+)'/", $src, $m)) {
            foreach ($m[1] as $code) {
                $wired[$code][$name] = true;
            }
        }
        // canAny('module', ['action', ...])
        if (preg_match_all("/->canAny\(\s*(?:\\\$[a-z_]+,\s*)?'([a-z_]+)'\s*,\s*\[([^\]]*)\]/", $src, $m)) {
            foreach ($m[1] as $i => $module) {
                preg_match_all("/'([a-z_]+)'/", $m[2][$i], $actions);
                foreach ($actions[1] as $action) {
                    $wired["{$module}.{$action}"][$name] = true;
                }
            }
        }
        // A bare quoted 'module.action' literal.
        if (preg_match_all("/'([a-z_]+\.[a-z_]+)'/", $src, $m)) {
            foreach ($m[1] as $code) {
                $wired[$code][$name] = true;
            }
        }
    }

    // AccessScopeService::canViewAllZones() calls can($user, $module,
    // CROSS_ZONE_ACTION), so every <module>.view_cross_zone is enforced for the
    // modules the scope service is actually asked about.
    foreach (['requests', 'staff', 'reports'] as $module) {
        $wired["{$module}.view_cross_zone"]['AccessScopeService::canViewAllZones'] = true;
    }

    return $wired;
}

it('has no decorative permission codes', function () {
    // Deliberate exceptions: codes that gate a frontend surface but no backend
    // check. Adding a new one requires adding it here, which is the review gate.
    $frontendOnly = [
        // Capabilities, not authorization. leave-sidebar.tsx requires
        // requests.approve_zone to surface the Approvals nav item for zone
        // oversight, and views/approvals.tsx uses it to enable buttons. The
        // server does not consult it, so granting it widens the UI but never
        // the API surface. requests.approve used to live here; since the
        // governance normalization RequestController::decide (via
        // LeaveRequestService::decide) enforces it alongside assignment
        // ownership, it is a real backend code now.
        'requests.approve_zone',
    ];

    $wired = permissionEnforcementPoints();
    $unenforced = [];

    // Declared in config/permission_enforcement.php rather than inline, so the
    // seeder, the lifecycle migration and this test cannot disagree about which
    // codes are intentionally frontend-only.
    expect(Permission::frontendOnlyCodes())->toBe($frontendOnly, 'The frontend-only allowlist moved; update the rationale above');

    foreach (Permission::orderBy('module')->orderBy('action')->get() as $p) {
        if (in_array($p->code, $frontendOnly, true)) {
            continue;
        }

        // Roadmap codes are visible in the matrix but ungrantable by
        // construction (status=planned, active=false): nothing can be
        // configured with them yet, so there is no decorative switch to
        // guard against. Anything implemented must be wired below.
        if ($p->status === 'planned' && ! $p->active) {
            continue;
        }

        if (! isset($wired[$p->code])) {
            $unenforced[] = $p->code;
        }
    }

    expect($unenforced)->toBe(
        [],
        "These codes are shown as switches in the RBAC matrix but nothing reads them:\n  - "
        .implode("\n  - ", $unenforced)
        ."\nWire them up, or delete them via a migration like 2026_10_03_120000."
    );
});

it('requires every implemented code to carry a reachable enforcement reference', function () {
    // status = 'implemented' is a claim, and a claim in a database column is
    // worthless unless something checks it. Each reference must point at a file
    // that exists and still mentions the code, so a code cannot be promoted to
    // implemented on the strength of a path that was renamed or deleted.
    $wired = permissionEnforcementPoints();
    $frontendOnly = Permission::frontendOnlyCodes();
    $problems = [];

    foreach (Permission::where('status', 'implemented')->get() as $p) {
        // Frontend-only capabilities are enforced by the SPA, not the API, so
        // there is no backend source file to resolve. The companion test asserts
        // they still gate a real surface rather than being unreferenced.
        if (in_array($p->code, $frontendOnly, true)) {
            continue;
        }

        if (blank($p->enforcement_reference)) {
            $problems[] = "{$p->code}: status=implemented but enforcement_reference is empty";

            continue;
        }

        // The reference is prose ("routes/api.php: POST ..."), so test the file
        // part rather than requiring the whole string to be a path.
        if (! preg_match('#([\w/\.-]+\.php)#', $p->enforcement_reference, $m)) {
            $problems[] = "{$p->code}: enforcement_reference names no source file ({$p->enforcement_reference})";

            continue;
        }

        $path = base_path($m[1]);

        if (! is_file($path)) {
            $problems[] = "{$p->code}: enforcement_reference points at a missing file ({$m[1]})";

            continue;
        }

        if (! isset($wired[$p->code])) {
            $problems[] = "{$p->code}: enforcement_reference claims enforcement but nothing reads this code";
        }
    }

    expect($problems)->toBe([], implode("\n  - ", $problems));
});

it('points every frontend-only capability at the surface it actually gates', function () {
    // A frontend-only code is legitimate only while a frontend file reads it.
    // Once that consumer is deleted the code is decorative and belongs in the
    // prune migration instead of lingering as a switch that does nothing.
    $root = dirname(base_path()).'/Leave Management system';
    $unreferenced = [];

    foreach (Permission::frontendOnlyCodes() as $code) {
        $consumers = [];

        foreach (['components', 'app', 'lib', 'hooks', 'frontend'] as $dir) {
            $path = "{$root}/{$dir}";

            if (! is_dir($path)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $f) {
                if ($f->isFile() && in_array($f->getExtension(), ['ts', 'tsx'], true)
                    && str_contains(file_get_contents($f->getPathname()), $code)) {
                    $consumers[] = substr($f->getPathname(), strlen($root) + 1);
                }
            }
        }

        if ($consumers === []) {
            $unreferenced[] = "{$code}: declared frontend-only but no .ts/.tsx file reads it";
        }
    }

    expect($unreferenced)->toBe([], implode("\n  - ", $unreferenced));
});

it('refuses to grant a code that is not enforced', function () {
    // A planned code may exist in the catalog and be listed in the matrix, but
    // no role may hold it: otherwise the switch implies a capability that no
    // route or policy consults, which is the exact defect the matrix drifted on.
    $hr = apiLogin('u3');

    $planned = Permission::where('code', 'requests.view')->firstOrFail();
    $planned->update(['status' => 'planned', 'active' => false]);

    // It vanishes from what the role can resolve, even mid-session.
    app(PermissionService::class)->forgetHoldersOf('HR');
    expect(app(PermissionService::class)->codesForRole('HR'))->not->toContain('requests.view');

    // And the API refuses to write it onto a role in the first place.
    $this->patchJson('/api/roles/HR', [
        'permissions' => ['requests.view'],
    ], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions');

    expect($planned->fresh()->status)->toBe('planned');
    expect($planned->fresh()->isGrantable())->toBeFalse();
});

it('keeps every catalogued module row in sync with the codes that use it', function () {
    // permissions.module is free text; the modules table is what makes it a real
    // reference. A code pointing at a module with no row would mean the module
    // screen and the matrix disagree about what exists.
    $orphanModules = Permission::distinct()->pluck('module')
        ->diff(DB::table('modules')->pluck('id'))
        ->values()
        ->all();

    expect($orphanModules)->toBe([], 'Permissions reference modules with no modules row: '.implode(', ', $orphanModules));

    // And no module row is decorative either.
    $unused = DB::table('modules')->pluck('id')
        ->diff(Permission::distinct()->pluck('module'))
        ->values()
        ->all();

    expect($unused)->toBe([], 'Modules rows exist with no codes: '.implode(', ', $unused));
});

it('does not offer a matrix switch for a code that no active role holds', function () {
    // A code nobody holds is dead weight in the catalog; a code held by nobody
    // also means the matching route can never be opened, which the grant-effect
    // suite guards separately.
    $codes = Permission::orderBy('module')->orderBy('action')->pluck('code');

    foreach ($codes as $code) {
        [$module, $action] = explode('.', $code, 2);

        expect(Role::where('active', true)->whereHas('permissions', fn ($q) => $q
            ->where('module', $module)
            ->where('action', $action))->count())
            ->toBeGreaterThan(0, "No active role holds {$code}, so nothing can be configured with it");
    }
});

it('leaves every role listable with its lifecycle state', function () {
    // The matrix needs to tell an archived role from a live one; without `active`
    // in the payload the UI cannot render the distinction and would let an admin
    // "edit" a role that grants nothing.
    $hr = apiLogin('u3');
    $roles = $this->getJson('/api/roles', ['Authorization' => "Bearer {$hr}"])->assertOk()->json('data');

    foreach ($roles as $role) {
        expect($role)->toHaveKeys(['id', 'name', 'active']);
        expect($role['active'])->toBeBool();
    }
});

it('keeps every route permission code inside the catalog', function () {
    $codes = Permission::pluck('code')->all();
    $missing = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'permission:')) {
                $code = substr($m, strlen('permission:'));

                if (! in_array($code, $codes, true)) {
                    $missing[] = "{$route->methods()[0]} api/{$route->uri()} requires {$code}";
                }
            }
        }
    }

    expect($missing)->toBe(
        [],
        "Routes require codes that are not in the catalog:\n  - ".implode("\n  - ", $missing)
    );
});

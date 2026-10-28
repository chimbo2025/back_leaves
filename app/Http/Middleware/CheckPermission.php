<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * usage: 'permission:requests.create' — the arg is a single `module.action`
 * code (or the `*` wildcard); it is split here so alias middleware receives
 * exactly one colon parameter.
 */
class CheckPermission
{
    public function __construct(protected PermissionService $permissions) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        [$module, $action] = array_pad(explode('.', $permission, 2), 2, null);

        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->is_active !== true) {
            return response()->json(['message' => 'Account deactivated.'], 403);
        }

        // No `*` wildcard. It used to short-circuit the check, so a route typo of
        // `permission:*` opened that route to every authenticated user.
        // PermissionService cannot produce `*` for a user, so honouring it would
        // only ever be a bypass. Refused outright, so the mistake fails loudly
        // rather than granting access.
        if ($module === '*') {
            return response()->json(['message' => 'Wildcard permissions are not supported.'], 500);
        }

        if (! $this->permissions->can($user, $module, $action)) {
            return response()->json(['message' => "Missing permission: {$module}.{$action}."], 403);
        }

        return $next($request);
    }
}

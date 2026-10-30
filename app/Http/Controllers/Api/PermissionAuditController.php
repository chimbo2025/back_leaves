<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PermissionAuditService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only RBAC audit endpoint for the settings console. It exposes no
 * mutation and is gated on admin.view.
 */
class PermissionAuditController extends Controller
{
    public function __construct(protected PermissionAuditService $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->audit->report()]);
    }
}

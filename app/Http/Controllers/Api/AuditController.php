<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => AuditLog::with('actor.employee')
                ->when($request->input('entity_type'), fn ($q, $v) => $q->where('entity_type', $v))
                ->when($request->input('entity_id'), fn ($q, $v) => $q->where('entity_id', $v))
                ->orderByDesc('id')
                ->paginate((int) $request->input('per_page', 50)),
        ]);
    }

    /** Chain-control endpoint: proves the ledger has not been tampered with. */
    public function verify(): JsonResponse
    {
        return response()->json(['data' => $this->audit->verify()]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Notification::with('recipient')
                ->where('recipient_employee_id', $request->user()->employee?->id)
                ->orderByDesc('sent_at')
                ->paginate((int) $request->input('per_page', 50)),
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->recipient_employee_id !== $request->user()->employee?->id) {
            return response()->json(['message' => 'Not yours to mark.'], 403);
        }
        $notification->update(['read_at' => now()]);

        return response()->json(['data' => $notification->fresh()]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread' => Notification::where('recipient_employee_id', $request->user()->employee?->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }
}

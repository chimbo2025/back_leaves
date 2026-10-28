<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 4 — a user manages their own notification preferences.
 *
 * A mandatory event type (approval, rejection, security, late-return) cannot be
 * disabled: the endpoint accepts the request but returns the effective state
 * with the channels still on, so the UI shows a locked switch rather than
 * silently storing an opt-out that would never be honoured.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(protected NotificationPreferenceService $preferences) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->preferences->forUser($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_type' => ['required', 'string'],
            'email_enabled' => ['sometimes', 'boolean'],
            'database_enabled' => ['sometimes', 'boolean'],
            'language' => ['sometimes', 'string', 'max:8'],
            'timing_config' => ['sometimes', 'array'],
        ]);

        $eventType = $data['event_type'];
        $known = array_merge(
            NotificationPreferenceService::MANDATORY_EVENTS,
            NotificationPreferenceService::OPTIONAL_EVENTS,
        );

        if (! in_array($eventType, $known, true)) {
            throw ValidationException::withMessages(['event_type' => 'Unknown notification event type.']);
        }

        if ($this->preferences->isMandatory($eventType)) {
            return response()->json([
                'data' => [
                    'event_type' => $eventType,
                    'mandatory' => true,
                    'email_enabled' => true,
                    'database_enabled' => true,
                    'language' => $data['language'] ?? 'en',
                    'timing_config' => $data['timing_config'] ?? [],
                ],
                'message' => 'This notification is mandatory and cannot be disabled.',
            ]);
        }

        $preference = $this->preferences->set($request->user(), $eventType, [
            'email_enabled' => $data['email_enabled'] ?? true,
            'database_enabled' => $data['database_enabled'] ?? true,
            'language' => $data['language'] ?? 'en',
            'timing_config_json' => $data['timing_config'] ?? null,
        ]);

        return response()->json(['data' => [
            'event_type' => $preference->event_type,
            'mandatory' => false,
            'email_enabled' => $preference->email_enabled,
            'database_enabled' => $preference->database_enabled,
            'language' => $preference->language,
            'timing_config' => $preference->timing_config_json ?? [],
        ]]);
    }
}

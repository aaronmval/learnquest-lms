<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The navbar's "System Alerts" drawer. Every query goes through the
 * authenticated user's own notifications() relation, so one user can never
 * read or change another user's alerts.
 */
class NotificationController extends Controller
{
    private const LIST_LIMIT = 30;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $alerts = $user->notifications()
            ->take(self::LIST_LIMIT)
            ->get()
            ->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'kind' => $n->data['kind'] ?? null,
                'title' => $n->data['title'] ?? 'Alert',
                'message' => $n->data['message'] ?? '',
                'url' => $n->data['url'] ?? null,
                'icon' => $n->data['icon'] ?? 'fa-bell',
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'notifications' => $alerts,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['id' => $notification->id, 'read' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    public function clear(Request $request): Response
    {
        $request->user()->notifications()->delete();

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function count(Request $request): JsonResponse
    {
        $baseQuery = AppNotification::query()->where('user_id', $request->user()->id);
        $total = (clone $baseQuery)->count();
        $unread = (clone $baseQuery)->whereNull('read_at')->count();

        return response()->json([
            'total' => $total,
            'unread' => $unread,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $notifications = AppNotification::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($notifications);
    }

    public function mark(Request $request, AppNotification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json($notification);
    }

    public function markAll(Request $request): JsonResponse
    {
        $updatedCount = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'تم تعليم كل الإشعارات كمقروءة',
            'updated' => $updatedCount,
        ]);
    }

    /**
     * Backward compatible alias.
     */
    public function markAsRead(Request $request, AppNotification $notification): JsonResponse
    {
        return $this->mark($request, $notification);
    }
}

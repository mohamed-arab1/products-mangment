<?php

namespace App\Services;

use App\Models\AppNotification;
use Illuminate\Support\Carbon;

class NotificationService
{
    /**
     * Create a notification while preventing duplicates via dedupe key.
     */
    public function notify(
        int $userId,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?string $dedupeKey = null,
        string $channel = 'in_app'
    ): AppNotification {
        if ($dedupeKey) {
            $existing = AppNotification::query()
                ->where('user_id', $userId)
                ->where('type', $type)
                ->where('dedupe_key', $dedupeKey)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return AppNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'dedupe_key' => $dedupeKey,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'channel' => $channel,
        ]);
    }

    public function hasRecentNotification(
        int $userId,
        string $type,
        string $dedupeKey,
        int $withinHours = 24
    ): bool {
        return AppNotification::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('dedupe_key', $dedupeKey)
            ->where('created_at', '>=', Carbon::now()->subHours($withinHours))
            ->exists();
    }
}

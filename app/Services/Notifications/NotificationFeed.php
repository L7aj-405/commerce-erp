<?php

namespace App\Services\Notifications;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\SensitiveDataRedactor;
use Illuminate\Database\Eloquent\Builder;

final class NotificationFeed
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    /** @return array<string, mixed> */
    public function summary(User $user, ?Organization $organization): array
    {
        if (! $organization) {
            return ['unread_count' => 0, 'recent' => [], 'poll_seconds' => $this->pollSeconds(), 'preferences' => $this->preferenceData($user)];
        }

        $query = $this->query($user, $organization);

        return [
            'unread_count' => (clone $query)->whereNull('read_at')->count(),
            'recent' => (clone $query)->latest()->limit((int) config('notifications.recent_limit', 8))->get()->map(fn (UserNotification $notification) => $this->present($notification))->all(),
            'poll_seconds' => $this->pollSeconds(),
            'preferences' => $this->preferenceData($user),
        ];
    }

    public function query(User $user, Organization $organization): Builder
    {
        return UserNotification::query()
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organization->getKey());
    }

    /** @return array<string, mixed> */
    public function present(UserNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'category' => $notification->category->value,
            'severity' => $notification->severity->value,
            'title' => $notification->title,
            'message' => $notification->message,
            'action_url' => $notification->action_url,
            'metadata' => SensitiveDataRedactor::sanitizeArray($notification->metadata ?? []),
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function preferenceData(User $user): array
    {
        $preference = $this->preferences->for($user);

        return [
            'sound_enabled' => $preference->sound_enabled,
            'sound_volume' => (float) $preference->sound_volume,
            'disabled_categories' => $preference->disabled_categories ?? [],
        ];
    }

    private function pollSeconds(): int
    {
        return max(10, min(30, (int) config('notifications.poll_seconds', 20)));
    }
}

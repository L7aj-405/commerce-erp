<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Collection;
use Illuminate\Database\UniqueConstraintViolationException;

final class NotificationPublisher
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    /** @return Collection<int, User> */
    public function recipientsWithPermission(Organization $organization, string $permission): Collection
    {
        return User::query()->whereHas('organizationMemberships', fn ($query) => $query
            ->where('organization_id', $organization->getKey())
            ->where('status', 'active')
            ->whereHas('role.permissions', fn ($permissions) => $permissions->where('key', $permission)))
            ->get();
    }

    /** @param iterable<User> $users @param array<string, mixed> $metadata */
    public function publish(
        Organization $organization,
        iterable $users,
        NotificationCategory $category,
        NotificationSeverity $severity,
        string $title,
        string $message,
        ?string $actionUrl,
        string $sourceType,
        string|int $sourceId,
        string $eventType,
        array $metadata = [],
    ): int {
        $created = 0;
        $dedupeKey = hash('sha256', implode('|', [$organization->getKey(), $sourceType, $sourceId, $eventType]));

        foreach ($users as $user) {
            if (! $this->hasActiveMembership($user, $organization) || ! $this->preferences->allows($user, $category, $severity)) {
                continue;
            }
            if (UserNotification::query()->where('user_id', $user->getKey())->where('dedupe_key', $dedupeKey)->exists()) {
                continue;
            }

            $notification = new UserNotification;
            $notification->user_id = $user->getKey();
            $notification->organization_id = $organization->getKey();
            $notification->category = $category;
            $notification->severity = $severity;
            $notification->title = SensitiveDataRedactor::text($title, 160);
            $notification->message = SensitiveDataRedactor::text($message, 600);
            $notification->action_url = $this->safeRelativeUrl($actionUrl);
            $notification->metadata = SensitiveDataRedactor::sanitizeArray($metadata);
            $notification->source_type = $sourceType;
            $notification->source_id = (string) $sourceId;
            $notification->event_type = $eventType;
            $notification->dedupe_key = $dedupeKey;
            try {
                $notification->save();
                $created++;
            } catch (UniqueConstraintViolationException) {
                // A concurrent producer won the per-user idempotency race.
            }
        }

        return $created;
    }

    /** @param array<string, mixed> $metadata */
    public function publishToUser(
        User $user,
        Organization $organization,
        NotificationCategory $category,
        NotificationSeverity $severity,
        string $title,
        string $message,
        ?string $actionUrl,
        string $sourceType,
        string|int $sourceId,
        string $eventType,
        array $metadata = [],
    ): int {
        return $this->publish($organization, [$user], $category, $severity, $title, $message, $actionUrl, $sourceType, $sourceId, $eventType, $metadata);
    }

    private function hasActiveMembership(User $user, Organization $organization): bool
    {
        return $user->organizationMemberships()->where('organization_id', $organization->getKey())->where('status', 'active')->exists();
    }

    private function safeRelativeUrl(?string $url): ?string
    {
        if (! $url || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }

        return mb_substr($url, 0, 1024);
    }
}

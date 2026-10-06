<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\User;
use App\Models\UserNotificationPreference;

final class NotificationPreferences
{
    public function for(User $user, bool $persist = false): UserNotificationPreference
    {
        $preference = UserNotificationPreference::query()->where('user_id', $user->getKey())->first();
        if ($preference) {
            return $preference;
        }

        $preference = new UserNotificationPreference;
        $preference->user_id = $user->getKey();
        $preference->sound_enabled = false;
        $preference->sound_volume = '0.50';
        $preference->disabled_categories = [];
        if ($persist) {
            $preference->save();
        }

        return $preference;
    }

    public function allows(User $user, NotificationCategory $category, NotificationSeverity $severity): bool
    {
        if ($severity === NotificationSeverity::Critical
            && in_array($category, [NotificationCategory::System, NotificationCategory::Security], true)) {
            return true;
        }

        return ! in_array($category->value, $this->for($user)->disabled_categories ?? [], true);
    }
}

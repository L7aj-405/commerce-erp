<?php

namespace App\Http\Controllers\Settings;

use App\Enums\NotificationCategory;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Notifications\NotificationFeed;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferenceController extends Controller
{
    public function edit(Request $request, NotificationFeed $feed): Response
    {
        return Inertia::render('Settings/NotificationPreferences', [
            'preferences' => $feed->preferenceData($request->user()),
            'categories' => NotificationCategory::values(),
            'mandatoryCriticalCategories' => [NotificationCategory::System->value, NotificationCategory::Security->value],
        ]);
    }

    public function update(Request $request, NotificationPreferences $preferences, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'sound_enabled' => ['required', 'boolean'],
            'sound_volume' => ['required', 'numeric', 'min:0', 'max:1'],
            'disabled_categories' => ['array'],
            'disabled_categories.*' => ['string', Rule::enum(NotificationCategory::class)],
        ]);
        $setting = $preferences->for($request->user(), true);
        $old = $setting->only(['sound_enabled', 'sound_volume', 'disabled_categories']);
        $setting->sound_enabled = (bool) $data['sound_enabled'];
        $setting->sound_volume = number_format((float) $data['sound_volume'], 2, '.', '');
        $setting->disabled_categories = array_values(array_unique($data['disabled_categories'] ?? []));
        $setting->save();

        $audit->record('notification.preferences_updated', $request->user(), $request->user()->activeOrganization, auditable: $setting, oldValues: $old, newValues: $setting->only(['sound_enabled', 'sound_volume', 'disabled_categories']));

        return back()->with('success', 'Préférences de notifications enregistrées.');
    }
}

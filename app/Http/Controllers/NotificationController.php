<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\UserNotification;
use App\Services\ActiveTenantContext;
use App\Services\Notifications\NotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context, NotificationFeed $feed): Response
    {
        $organization = $context->organizationOrFail();
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'unread', 'read'])],
            'category' => ['nullable', Rule::enum(NotificationCategory::class)],
            'severity' => ['nullable', Rule::enum(NotificationSeverity::class)],
        ]);
        $query = $feed->query($request->user(), $organization);
        $status = $filters['status'] ?? 'all';
        $query->when($status === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($status === 'read', fn ($query) => $query->whereNotNull('read_at'))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['severity'] ?? null, fn ($query, $severity) => $query->where('severity', $severity));

        $notifications = $query->latest()->paginate(20)->withQueryString();
        $notifications->through(fn (UserNotification $notification) => $feed->present($notification));

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'filters' => ['status' => $status, 'category' => $filters['category'] ?? '', 'severity' => $filters['severity'] ?? ''],
            'categories' => NotificationCategory::values(),
            'severities' => array_column(NotificationSeverity::cases(), 'value'),
        ]);
    }

    public function feed(Request $request, ActiveTenantContext $context, NotificationFeed $feed): JsonResponse
    {
        return response()->json($feed->summary($request->user(), $context->organizationOrFail()));
    }

    public function markRead(Request $request, string $notification, ActiveTenantContext $context): JsonResponse|RedirectResponse
    {
        $item = $this->owned($request, $notification, $context);
        $item->read_at ??= now();
        $item->save();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function markUnread(Request $request, string $notification, ActiveTenantContext $context): JsonResponse|RedirectResponse
    {
        $item = $this->owned($request, $notification, $context);
        $item->read_at = null;
        $item->save();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function markAllRead(Request $request, ActiveTenantContext $context): JsonResponse|RedirectResponse
    {
        UserNotification::query()->where('user_id', $request->user()->getKey())
            ->where('organization_id', $context->organizationOrFail()->getKey())
            ->whereNull('read_at')->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    private function owned(Request $request, string $id, ActiveTenantContext $context): UserNotification
    {
        return UserNotification::query()
            ->whereKey($id)
            ->where('user_id', $request->user()->getKey())
            ->where('organization_id', $context->organizationOrFail()->getKey())
            ->firstOrFail();
    }
}

<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ActivityAuditController extends Controller
{
    public function __invoke(Request $request, ActiveTenantContext $context, AuditLogPresenter $presenter): Response
    {
        $activeOrganization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($activeOrganization, 'audit.view'), 403);

        $organizations = Organization::query()
            ->where('status', 'active')
            ->whereHas('memberships', fn (Builder $query) => $query
                ->where('user_id', $request->user()->getKey())
                ->where('status', 'active')
                ->whereHas('role.permissions', fn (Builder $permissions) => $permissions->where('key', 'audit.view')))
            ->orderBy('name')
            ->get(['id', 'name']);
        $allowedOrganizationIds = $organizations->pluck('id')->map(fn ($id) => (int) $id)->all();

        $filters = $request->validate([
            'organization_id' => ['nullable', 'integer', Rule::in($allowedOrganizationIds)],
            'user_id' => ['nullable', 'integer'],
            'module' => ['nullable', 'string', 'max:64'],
            'event' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:255'],
            'detail' => ['nullable', 'integer'],
        ]);

        $organizationId = (int) ($filters['organization_id'] ?? $activeOrganization->getKey());
        abort_unless(in_array($organizationId, $allowedOrganizationIds, true), 403);

        $base = AuditLog::query()->where('organization_id', $organizationId);
        $events = (clone $base)->distinct()->orderBy('event')->pluck('event')->all();
        $modulePrefixes = $presenter->modulePrefixes((string) ($filters['module'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        $query = (clone $base)
            ->with(['actor:id,name,email', 'organization:id,name', 'store:id,name,code'])
            ->when($filters['user_id'] ?? null, fn (Builder $query, $actorId) => $query->where('actor_id', $actorId))
            ->when($filters['event'] ?? null, fn (Builder $query, $event) => $query->where('event', $event))
            ->when($modulePrefixes !== [], function (Builder $query) use ($modulePrefixes) {
                $query->where(function (Builder $query) use ($modulePrefixes) {
                    foreach ($modulePrefixes as $prefix) {
                        $query->orWhere('event', 'like', $prefix.'%');
                    }
                });
            })
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search) {
                $query->where('event', 'like', "%{$search}%")
                    ->orWhere('auditable_type', 'like', "%{$search}%")
                    ->orWhere('old_values', 'like', "%{$search}%")
                    ->orWhere('new_values', 'like', "%{$search}%")
                    ->orWhereHas('actor', fn (Builder $actor) => $actor
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            }));

        $logs = $query->latest('id')->paginate(25)->withQueryString();
        $logs->through(function (AuditLog $log) use ($presenter, $request) {
            return [
                ...$presenter->present($log),
                'detail_url' => $request->fullUrlWithQuery(['detail' => $log->getKey()]),
            ];
        });

        $actorIds = (clone $base)->whereNotNull('actor_id')->distinct()->pluck('actor_id');
        $users = User::query()->whereIn('id', $actorIds)->orderBy('name')->get(['id', 'name', 'email']);

        $detail = null;
        if (isset($filters['detail'])) {
            $detailLog = (clone $base)->with(['actor:id,name,email', 'organization:id,name', 'store:id,name,code'])
                ->whereKey((int) $filters['detail'])->firstOrFail();
            $detail = $presenter->present($detailLog);
        }

        return Inertia::render('Activity/Index', [
            'logs' => $logs,
            'detail' => $detail,
            'filters' => [
                ...$request->only(['user_id', 'module', 'event', 'date_from', 'date_to', 'search']),
                'organization_id' => $organizationId,
            ],
            'organizations' => $organizations,
            'users' => $users,
            'modules' => $presenter->availableModules($events),
            'events' => collect($events)->map(fn (string $event) => [
                'key' => $event,
                'label' => $presenter->eventLabel($event),
            ])->values(),
            'closeDetailUrl' => $request->fullUrlWithoutQuery('detail'),
        ]);
    }
}

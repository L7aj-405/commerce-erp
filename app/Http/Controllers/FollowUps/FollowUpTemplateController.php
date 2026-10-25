<?php

namespace App\Http\Controllers\FollowUps;

use App\Actions\FollowUps\SaveFollowUpTemplateAction;
use App\Http\Controllers\Controller;
use App\Models\FollowUpTemplate;
use App\Services\ActiveTenantContext;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\FollowUps\FollowUpRecurringQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Work Center "Templates" management (follow_ups.manage_all): transport only. */
class FollowUpTemplateController extends Controller
{
    private const INPUT = ['name', 'title', 'default_note', 'default_priority', 'default_due_offset_days', 'default_time', 'active'];

    public function index(Request $request, ActiveTenantContext $context, FollowUpAccess $access, FollowUpRecurringQuery $query): Response
    {
        $organization = $context->organizationOrFail();
        $access->authorize($access->canManageAll($request->user(), $organization));
        $page = FollowUpTemplate::query()->where('organization_id', $organization->getKey())
            ->orderByDesc('active')->orderBy('name')->paginate(50)->withQueryString();

        return Inertia::render('Work/Templates', [
            'templates' => [
                'data' => collect($page->items())->map(fn (FollowUpTemplate $template) => $query->presentTemplate($template))->all(),
                'links' => $page->linkCollection()->toArray(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, SaveFollowUpTemplateAction $action): RedirectResponse
    {
        $action->create($request->user(), $context->organizationOrFail(), $request->only(self::INPUT));

        return back()->with('success', 'Modèle créé.');
    }

    public function update(Request $request, ActiveTenantContext $context, FollowUpTemplate $followUpTemplate, SaveFollowUpTemplateAction $action): RedirectResponse
    {
        $action->update($request->user(), $context->organizationOrFail(), $followUpTemplate, $request->only(self::INPUT));

        return back()->with('success', 'Modèle mis à jour.');
    }
}

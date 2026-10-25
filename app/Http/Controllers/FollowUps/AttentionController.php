<?php

namespace App\Http\Controllers\FollowUps;

use App\Actions\Attention\HandleAttentionItemAction;
use App\Http\Controllers\Controller;
use App\Models\AttentionItem;
use App\Services\ActiveTenantContext;
use App\Services\Attention\AttentionQuery;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\FollowUps\FollowUpQuery;
use App\Services\FollowUps\QuotationOpener;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Work Center "Attention" (W4.1): transport only — rules live in the detector, AttentionQuery and HandleAttentionItemAction. */
class AttentionController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context, AttentionQuery $attention, FollowUpQuery $followUps, FollowUpAccess $access): Response
    {
        $organization = $context->organizationOrFail();
        $user = $request->user();
        $access->authorize($attention->canViewAny($user, $organization));
        $filters = array_filter($request->validate(AttentionQuery::rules()), fn ($value) => $value !== null && $value !== '');
        $filters['view'] ??= 'open';
        $page = $attention->search($user, $organization, $filters);

        return Inertia::render('Work/Attention', [
            'filters' => $filters,
            'items' => [
                'data' => collect($page->items())->map(fn (AttentionItem $item) => $attention->present($user, $organization, $item))->all(),
                'links' => $page->linkCollection()->toArray(),
                'total' => $page->total(),
            ],
            'context' => $followUps->formContext($user, $organization),
        ]);
    }

    public function snooze(Request $request, ActiveTenantContext $context, AttentionItem $attentionItem, HandleAttentionItemAction $action): RedirectResponse
    {
        $action->snooze($request->user(), $context->organizationOrFail(), $attentionItem, $request->only(['preset', 'until_date', 'expected_version']));

        return back()->with('success', 'Alerte reportée.');
    }

    public function dismiss(Request $request, ActiveTenantContext $context, AttentionItem $attentionItem, HandleAttentionItemAction $action): RedirectResponse
    {
        $action->dismiss($request->user(), $context->organizationOrFail(), $attentionItem, $request->only(['note', 'expected_version']));

        return back()->with('success', 'Alerte ignorée.');
    }

    public function followUp(Request $request, ActiveTenantContext $context, AttentionItem $attentionItem, HandleAttentionItemAction $action): RedirectResponse
    {
        $action->createFollowUp($request->user(), $context->organizationOrFail(), $attentionItem, $request->all());

        return back()->with('success', 'Suivi créé.');
    }

    public function openQuotation(Request $request, ActiveTenantContext $context, AttentionItem $attentionItem, AttentionQuery $attention, FollowUpAccess $access, QuotationOpener $opener): RedirectResponse
    {
        $access->authorize($attention->canView($request->user(), $attentionItem));

        return $opener->open($request->user(), $context, $attentionItem->quotation_id === null ? null : (int) $attentionItem->quotation_id);
    }
}

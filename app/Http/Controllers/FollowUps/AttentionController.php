<?php

namespace App\Http\Controllers\FollowUps;

use App\Actions\Attention\HandleAttentionItemAction;
use App\Http\Controllers\Controller;
use App\Models\AttentionItem;
use App\Models\Organization;
use App\Services\ActiveTenantContext;
use App\Services\Attention\AttentionOwnership;
use App\Services\Attention\AttentionQuery;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\FollowUps\FollowUpQuery;
use App\Services\FollowUps\StoreDocumentOpener;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Work Center "Attention" (W4.1): transport only — rules live in the detector, AttentionQuery and HandleAttentionItemAction. */
class AttentionController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context, AttentionQuery $attention, FollowUpQuery $followUps, FollowUpAccess $access, AttentionOwnership $ownership): Response
    {
        $organization = $context->organizationOrFail();
        $user = $request->user();
        $access->authorize($attention->canViewAny($user, $organization));
        $filters = array_filter($request->validate(AttentionQuery::rules()), fn ($value) => $value !== null && $value !== '');
        $filters['view'] ??= 'open';
        $filters['assigned'] ??= $attention->defaultAssigned($user, $organization);
        $page = $attention->search($user, $organization, $filters);

        return Inertia::render('Work/Attention', [
            'filters' => $filters,
            'items' => [
                'data' => collect($page->items())->map(fn (AttentionItem $item) => $attention->present($user, $organization, $item))->all(),
                'links' => $page->linkCollection()->toArray(),
                'total' => $page->total(),
            ],
            'context' => $followUps->formContext($user, $organization),
            // Managers: valid owners with their stores and the subjects they may own (the server re-validates every assignment).
            'owners' => $access->canManageAll($user, $organization) ? $this->owners($organization, $ownership) : [],
        ]);
    }

    /** @return list<array{id: int, name: string, store_ids: list<int>, subjects: list<string>}> */
    private function owners(Organization $organization, AttentionOwnership $ownership): array
    {
        $owners = [];
        foreach ([AttentionItem::SUBJECT_QUOTATION, AttentionItem::SUBJECT_SALES_ORDER, AttentionItem::SUBJECT_INVOICE] as $subject) {
            foreach ($ownership->candidates($organization, AttentionOwnership::permissionFor($subject)) as $candidate) {
                $owners[$candidate['id']] ??= [...$candidate, 'subjects' => []];
                $owners[$candidate['id']]['subjects'][] = $subject;
            }
        }

        return array_values($owners);
    }

    public function claim(Request $request, ActiveTenantContext $context, AttentionItem $attentionItem, HandleAttentionItemAction $action): RedirectResponse
    {
        $action->claim($request->user(), $context->organizationOrFail(), $attentionItem, $request->only(['expected_version']));

        return back()->with('success', 'Alerte prise en charge.');
    }

    public function assign(Request $request, ActiveTenantContext $context, AttentionItem $attentionItem, HandleAttentionItemAction $action): RedirectResponse
    {
        $action->assign($request->user(), $context->organizationOrFail(), $attentionItem, $request->only(['assigned_user_id', 'expected_version']));

        return back()->with('success', 'Responsable mis à jour.');
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

    public function openQuotation(Request $request, ActiveTenantContext $context, AttentionItem $attentionItem, AttentionQuery $attention, FollowUpAccess $access, StoreDocumentOpener $opener): RedirectResponse
    {
        $access->authorize($attention->canView($request->user(), $attentionItem));

        return $opener->openQuotation($request->user(), $context, $attentionItem->quotation_id === null ? null : (int) $attentionItem->quotation_id);
    }

    public function openSalesOrder(Request $request, ActiveTenantContext $context, AttentionItem $attentionItem, AttentionQuery $attention, FollowUpAccess $access, StoreDocumentOpener $opener): RedirectResponse
    {
        $access->authorize($attention->canView($request->user(), $attentionItem));

        return $opener->openSalesOrder($request->user(), $context, $attentionItem->sales_order_id === null ? null : (int) $attentionItem->sales_order_id);
    }

    public function openInvoice(Request $request, ActiveTenantContext $context, AttentionItem $attentionItem, AttentionQuery $attention, FollowUpAccess $access, StoreDocumentOpener $opener): RedirectResponse
    {
        $access->authorize($attention->canView($request->user(), $attentionItem));

        return $opener->openInvoice($request->user(), $context, $attentionItem->invoice_id === null ? null : (int) $attentionItem->invoice_id);
    }
}

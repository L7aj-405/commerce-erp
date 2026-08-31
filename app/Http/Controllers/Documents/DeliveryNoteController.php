<?php

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\CancelDeliveryNoteDraftAction;
use App\Actions\Documents\CreateFullDeliveryNoteFromSalesOrderAction;
use App\Actions\Documents\IssueDeliveryNoteAction;
use App\Actions\Documents\UpdateDeliveryNoteDraftAction;
use App\Enums\DeliveryNoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\CreateDeliveryNoteRequest;
use App\Http\Requests\Documents\UpdateDeliveryNoteDraftRequest;
use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Services\ActiveTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DeliveryNoteController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $store = $context->storeOrFail();
        $this->authorize('viewAny', [DeliveryNote::class, $organization, $store]);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(DeliveryNoteStatus::class)],
            'delivery_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $notes = DeliveryNote::query()->where('organization_id', $organization->getKey())->where('store_id', $store->getKey())
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('delivery_note_number', 'like', "%{$search}%")
                ->orWhere('recipient_name', 'like', "%{$search}%")
                ->orWhere('recipient_company', 'like', "%{$search}%")
                ->orWhereHas('salesOrder', fn ($query) => $query->where('order_number', 'like', "%{$search}%"))))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['delivery_date'] ?? null, fn ($query, string $date) => $query->whereDate('delivery_date', $date))
            ->with(['store:id,name,code', 'salesOrder:id,order_number'])
            ->latest('delivery_date')->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('Documents/DeliveryNotes/Index', ['deliveryNotes' => $notes, 'filters' => $filters]);
    }

    public function store(CreateDeliveryNoteRequest $request, SalesOrder $order, CreateFullDeliveryNoteFromSalesOrderAction $action): RedirectResponse
    {
        $this->authorize('create', [DeliveryNote::class, $order]);
        $note = $action->execute($request->user(), $order, $request->validated());

        return redirect()->route('delivery-notes.show', $note);
    }

    public function show(Request $request, DeliveryNote $deliveryNote): Response
    {
        $this->authorize('view', $deliveryNote);
        $deliveryNote->load(['store:id,name,code', 'salesOrder:id,order_number', 'lines', 'issuedBy:id,name', 'cancelledBy:id,name']);

        return Inertia::render('Documents/DeliveryNotes/Show', [
            'deliveryNote' => $deliveryNote,
            'can' => [
                'updateDraft' => $request->user()->can('updateDraft', $deliveryNote),
                'issue' => $request->user()->can('issue', $deliveryNote),
                'backdate' => $request->user()->hasPermission($deliveryNote->organization_id, 'delivery_notes.backdate'),
                'email' => $request->user()->can('email', $deliveryNote),
            ],
        ]);
    }

    public function update(UpdateDeliveryNoteDraftRequest $request, DeliveryNote $deliveryNote, UpdateDeliveryNoteDraftAction $action): RedirectResponse
    {
        $this->authorize('updateDraft', $deliveryNote);
        $action->execute($request->user(), $deliveryNote, $request->validated());

        return back();
    }

    public function issue(Request $request, DeliveryNote $deliveryNote, IssueDeliveryNoteAction $action): RedirectResponse
    {
        $this->authorize('issue', $deliveryNote);
        $action->execute($request->user(), $deliveryNote);

        return back();
    }

    public function cancel(Request $request, DeliveryNote $deliveryNote, CancelDeliveryNoteDraftAction $action): RedirectResponse
    {
        $this->authorize('updateDraft', $deliveryNote);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $action->execute($request->user(), $deliveryNote, $data['reason'] ?? null);

        return back();
    }
}

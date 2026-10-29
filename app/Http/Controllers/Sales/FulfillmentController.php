<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Fulfillment\AttachFulfillmentProofAction;
use App\Actions\Fulfillment\CancelFulfillmentAction;
use App\Actions\Fulfillment\CloseFulfillmentAction;
use App\Actions\Fulfillment\HandFulfillmentToCarrierAction;
use App\Actions\Fulfillment\MarkFulfillmentDeliveredAction;
use App\Actions\Fulfillment\MarkFulfillmentInTransitAction;
use App\Actions\Fulfillment\MarkFulfillmentReadyAction;
use App\Actions\Fulfillment\RemoveFulfillmentProofAction;
use App\Actions\Fulfillment\StartFulfillmentPreparationAction;
use App\Actions\Fulfillment\UpdateFulfillmentShipmentDetailsAction;
use App\Http\Controllers\Controller;
use App\Models\Fulfillment;
use App\Models\FulfillmentAttachment;
use App\Models\OrganizationContact;
use App\Models\SalesOrder;
use App\Services\Fulfillment\FulfillmentEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Expédition & Livraison" transport: shape validation and routing to the
 * canonical App\Actions\Fulfillment actions, which own authorization,
 * tenancy, transitions, quantities, stock and audit.
 */
class FulfillmentController extends Controller
{
    public function startPreparation(Request $request, SalesOrder $order, StartFulfillmentPreparationAction $action): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array'], 'note' => ['nullable', 'string', 'max:2000'],
            'client_operation_id' => ['nullable', 'uuid'],
        ]);
        $action->execute($request->user(), $order, $data['items'], $data['note'] ?? null, $data['client_operation_id'] ?? null);

        return back()->with('success', 'Préparation de l’expédition commencée.');
    }

    /** Simple mode: a new shipment of explicit quantities handed to the carrier. */
    public function handNewToCarrier(Request $request, SalesOrder $order, HandFulfillmentToCarrierAction $action, AttachFulfillmentProofAction $attach): RedirectResponse
    {
        return $this->handToCarrier($request, $order, $action, $attach, null);
    }

    /** A ready shipment (advanced mode) handed to the carrier. */
    public function handReadyToCarrier(Request $request, SalesOrder $order, Fulfillment $fulfillment, HandFulfillmentToCarrierAction $action, AttachFulfillmentProofAction $attach): RedirectResponse
    {
        return $this->handToCarrier($request, $order, $action, $attach, $fulfillment);
    }

    private function handToCarrier(Request $request, SalesOrder $order, HandFulfillmentToCarrierAction $action, AttachFulfillmentProofAction $attach, ?Fulfillment $fulfillment): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'client_operation_id' => ['nullable', 'uuid'],
            'expected_status' => ['nullable', 'string', 'max:24'],
            'proofs' => ['nullable', 'array', 'max:'.(int) config('fulfillment.attachments.max_files', 5)],
            'proofs.*' => AttachFulfillmentProofAction::fileRules(),
        ]);
        $fulfillment = $action->execute($request->user(), $order, $request->only([...array_keys(HandFulfillmentToCarrierAction::rules()), 'items']),
            $fulfillment, $data['expected_status'] ?? null, $data['client_operation_id'] ?? null);
        if (($data['proofs'] ?? []) !== []) {
            $attach->execute($request->user(), $order, $fulfillment, $data['proofs'], 'shipping_proof');
        }

        return back()->with('success', 'Remis au transporteur.');
    }

    public function markReady(Request $request, SalesOrder $order, Fulfillment $fulfillment, MarkFulfillmentReadyAction $action): RedirectResponse
    {
        $action->execute($request->user(), $order, $fulfillment, $this->expected($request));

        return back()->with('success', 'Expédition prête.');
    }

    public function markInTransit(Request $request, SalesOrder $order, Fulfillment $fulfillment, MarkFulfillmentInTransitAction $action): RedirectResponse
    {
        $action->execute($request->user(), $order, $fulfillment, $this->expected($request));

        return back()->with('success', 'Expédition en transit.');
    }

    public function markDelivered(Request $request, SalesOrder $order, Fulfillment $fulfillment, MarkFulfillmentDeliveredAction $action): RedirectResponse
    {
        $data = $request->validate(['delivered_at' => ['nullable', 'date']]);
        $action->execute($request->user(), $order, $fulfillment, $data['delivered_at'] ?? null, $this->expected($request));

        return back()->with('success', 'Expédition livrée.');
    }

    public function close(Request $request, SalesOrder $order, Fulfillment $fulfillment, CloseFulfillmentAction $action): RedirectResponse
    {
        $action->execute($request->user(), $order, $fulfillment, $this->expected($request));

        return back()->with('success', 'Expédition clôturée.');
    }

    public function cancel(Request $request, SalesOrder $order, Fulfillment $fulfillment, CancelFulfillmentAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $action->execute($request->user(), $order, $fulfillment, $data['reason'] ?? null, $this->expected($request));

        return back()->with('success', 'Expédition annulée.');
    }

    public function update(Request $request, SalesOrder $order, Fulfillment $fulfillment, UpdateFulfillmentShipmentDetailsAction $action): RedirectResponse
    {
        $action->execute($request->user(), $order, $fulfillment, $request->only(['tracking_number', 'carrier_reference', 'package_count', 'expected_delivery_at', 'note']), $this->expected($request));

        return back()->with('success', 'Expédition mise à jour.');
    }

    public function attach(Request $request, SalesOrder $order, Fulfillment $fulfillment, AttachFulfillmentProofAction $action): RedirectResponse
    {
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.(int) config('fulfillment.attachments.max_files', 5)],
            'files.*' => AttachFulfillmentProofAction::fileRules(),
            'category' => ['nullable', 'in:'.implode(',', FulfillmentAttachment::CATEGORIES)],
        ]);
        $action->execute($request->user(), $order, $fulfillment, $data['files'], $data['category'] ?? 'shipping_proof');

        return back()->with('success', 'Justificatif ajouté.');
    }

    public function removeAttachment(Request $request, SalesOrder $order, Fulfillment $fulfillment, FulfillmentAttachment $attachment, RemoveFulfillmentProofAction $action): RedirectResponse
    {
        $action->execute($request->user(), $order, $fulfillment, $attachment);

        return back()->with('success', 'Justificatif supprimé.');
    }

    /** Streams a proof from the private disk after the same tenant/store/permission checks as the actions. */
    public function showAttachment(Request $request, SalesOrder $order, Fulfillment $fulfillment, FulfillmentAttachment $attachment, FulfillmentEngine $engine): StreamedResponse
    {
        $engine->authorize($request->user(), $order, 'fulfillment.view');
        abort_unless((int) $fulfillment->organization_id === (int) $order->organization_id && (int) $fulfillment->sales_order_id === (int) $order->getKey()
            && (int) $attachment->organization_id === (int) $order->organization_id && $attachment->fulfillment_id === $fulfillment->getKey(), 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Active transport contacts of the organization for the dispatch dialog (bounded autocomplete). */
    public function carrierOptions(Request $request, SalesOrder $order, FulfillmentEngine $engine): JsonResponse
    {
        $engine->authorize($request->user(), $order, 'fulfillment.dispatch');
        $search = trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:255']])['search'] ?? ''));

        return response()->json(['data' => OrganizationContact::query()->where('organization_id', $order->organization_id)
            ->where('contact_type', OrganizationContact::TYPE_TRANSPORT)->where('active', true)
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('full_name', 'like', "%{$search}%")->orWhere('company_name', 'like', "%{$search}%")))
            ->orderByRaw('coalesce(company_name, full_name)')->limit(20)->get(['id', 'full_name', 'company_name', 'phone'])
            ->map(fn (OrganizationContact $contact) => [
                'id' => $contact->getKey(), 'name' => $contact->company_name ?: $contact->full_name,
                'contact' => $contact->company_name ? $contact->full_name : null, 'phone' => $contact->phone,
            ])]);
    }

    private function expected(Request $request): ?string
    {
        return $request->validate(['expected_status' => ['nullable', 'string', 'max:24']])['expected_status'] ?? null;
    }
}

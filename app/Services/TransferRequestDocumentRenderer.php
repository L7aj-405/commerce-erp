<?php

namespace App\Services;

use App\Models\TransferRequest;

/**
 * Renders the "Bon de sortie" — an internal warehouse/logistics justificatif for
 * a Transfer Request. Never shows prices, HT/TVA/TTC or any finance data; the
 * chauffeur / warehouse names come from the request's own stored snapshot so an
 * old Bon does not change if a profile is later edited.
 */
class TransferRequestDocumentRenderer
{
    public function __construct(
        private readonly DocumentTemplateRegistry $templates,
        private readonly DocumentSellerProfile $seller,
        private readonly DocumentValueFormatter $format,
    ) {}

    /** @return array<string, mixed> */
    public function payload(TransferRequest $request): array
    {
        $request->loadMissing([
            'organization',
            'sourceWarehouse:id,name,code',
            'destinationWarehouse:id,name,code',
            'salesOrder:id,order_number',
            'lines.productVariant:id,product_id,label,sku,reference',
            'lines.productVariant.product:id,name,default_unit_id',
            'lines.productVariant.product.defaultUnit:id,name,symbol',
        ]);

        $lines = $request->lines->map(fn ($line) => [
            'reference' => $line->productVariant?->reference ?: $line->productVariant?->sku ?: '—',
            'description' => trim(($line->productVariant?->product?->name ?? '').' '.($line->productVariant?->label ?? '')),
            'quantity' => $this->format->decimal($line->quantity),
            'unit' => $line->productVariant?->product?->defaultUnit?->symbol
                ?? $line->productVariant?->product?->defaultUnit?->name
                ?? '',
            'reason' => $line->reason->label(),
        ])->all();

        return [
            'title' => 'Bon de sortie',
            'seller' => $this->seller->organizationIdentity($request->organization),
            'document' => [
                'number' => $request->request_number,
                'date' => $this->format->date($request->prepared_at ?? $request->shipped_at ?? $request->requested_at ?? now()),
                'status_label' => $this->statusLabel($request),
            ],
            'source' => $request->sourceWarehouse?->only(['name', 'code']),
            'destination' => $request->destinationWarehouse?->only(['name', 'code']),
            'order_number' => $request->salesOrder?->order_number,
            'driver' => [
                'name' => $request->driver_name,
                'phone' => $request->driver_phone,
                'vehicle' => $request->vehicle,
                'registration' => $request->vehicle_registration,
            ],
            'note' => $request->shipping_note,
            'lines' => $lines,
            // Two half-page copies fit comfortably on one A4 up to this many
            // article rows; above it, print two full identical pages instead
            // rather than shrink the table (§9).
            'two_on_one_page' => count($lines) <= 8,
        ];
    }

    public function html(TransferRequest $request): string
    {
        return view($this->templates->transferRequestView('v1'), $this->payload($request))->render();
    }

    private function statusLabel(TransferRequest $request): string
    {
        return match ($request->status->value) {
            'preparing' => 'En préparation',
            'shipped' => 'Expédiée',
            'received' => 'Réceptionnée',
            default => ucfirst($request->status->value),
        };
    }
}

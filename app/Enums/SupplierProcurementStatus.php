<?php

namespace App\Enums;

/**
 * Lifecycle of ONE supplier special-order requirement raised for a customer
 * Sales Order line whose quantity company stock cannot cover.
 *
 *   pending_supplier → supplier_confirmed → ordered → received → completed
 *                                            ↘ cancelled
 *   (pending_supplier | supplier_confirmed) ↘ unavailable
 *
 * `received` means the goods physically arrived and were immediately earmarked
 * for the linked Sales Order (never free stock). `completed` is reserved for a
 * later phase that closes the loop once the customer has taken delivery.
 */
enum SupplierProcurementStatus: string
{
    case PendingSupplier = 'pending_supplier';
    case SupplierConfirmed = 'supplier_confirmed';
    case Ordered = 'ordered';
    case Received = 'received';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::PendingSupplier => 'En attente fournisseur',
            self::SupplierConfirmed => 'Disponibilité confirmée',
            self::Ordered => 'Commandé au fournisseur',
            self::Received => 'Réceptionné (réservé commande)',
            self::Completed => 'Clôturé',
            self::Cancelled => 'Annulé',
            self::Unavailable => 'Indisponible',
        };
    }

    /**
     * The quantity still counts as a valid commitment covering the customer
     * line — i.e. it may be counted towards order-confirmation coverage and it
     * still has to be resolved operationally.
     */
    public function coversSalesLine(): bool
    {
        return in_array($this, [self::SupplierConfirmed, self::Ordered, self::Received, self::Completed], true);
    }

    /** No supplier order has left yet — a plain cancel is still safe. */
    public function isCancellableWithoutDecision(): bool
    {
        return in_array($this, [self::PendingSupplier, self::SupplierConfirmed, self::Unavailable], true);
    }

    /** The supplier / quantity / availability may still be edited freely. */
    public function isEditable(): bool
    {
        return in_array($this, [self::PendingSupplier, self::SupplierConfirmed], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }
}

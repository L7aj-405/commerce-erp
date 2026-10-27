<?php

namespace App\Enums;

/**
 * Outcome of an internal "ask the supplier" request for ONE Devis line, before
 * the Devis is issued. Not a purchase commitment — Sales Order procurement
 * (SupplierAvailabilityStatus) stays a separate, later workflow.
 */
enum QuotationSupplierAvailabilityStatus: string
{
    /** The supplier has not answered yet. */
    case Pending = 'pending';

    /** The supplier confirmed the full requested quantity. */
    case Available = 'available';

    /** The supplier confirmed less than the requested quantity. */
    case Partial = 'partial';

    /** The supplier cannot supply. */
    case Unavailable = 'unavailable';

    /** An authorized user attested availability verified outside the ERP workflow. */
    case ConfirmedManually = 'confirmed_manually';

    /** No longer relevant. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Demande en attente',
            self::Available => 'Disponible',
            self::Partial => 'Partiellement disponible',
            self::Unavailable => 'Indisponible',
            self::ConfirmedManually => 'Confirmé manuellement',
            self::Cancelled => 'Annulée',
        };
    }

    /** Only these statuses can count towards the quotation issue gate. */
    public function satisfiesGate(): bool
    {
        return in_array($this, [self::Available, self::ConfirmedManually], true);
    }

    /** A request still waiting for, or carrying, a supplier answer. */
    public function isOpen(): bool
    {
        return $this !== self::Cancelled;
    }

    /** Statuses whose result may still be (re)recorded by the supplier owner. */
    public function isResolvable(): bool
    {
        return in_array($this, [self::Pending, self::Available, self::Partial, self::Unavailable], true);
    }
}

<?php

namespace App\Enums;

enum QuotationStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Converted = 'converted';

    /**
     * A previously issued Devis that has been replaced by an issued revision.
     * It stays immutable and its PDF stays available; it is no longer the
     * current, shareable, decidable or convertible document.
     */
    case Superseded = 'superseded';

    /** Statuses from which a Devis may still be edited line-by-line. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Statuses that hold an official number and a shareable PDF. */
    public function isOfficial(): bool
    {
        return in_array($this, [self::Issued, self::Accepted, self::Rejected, self::Expired, self::Converted, self::Superseded], true);
    }

    /** The single "current" version of a commercial proposal can still move forward. */
    public function isCurrentEligible(): bool
    {
        return $this->isOfficial() && ! in_array($this, [self::Converted, self::Superseded], true);
    }
}

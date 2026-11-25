<?php

namespace App\Enums;

/** Stable system role keys. The key is the identifier; the label is display-only (French default). */
enum ContactSystemRole: string
{
    case Client = 'client';
    case Supplier = 'supplier';
    case Internal = 'internal';
    case Provider = 'provider';
    case Carrier = 'carrier';
    case Partner = 'partner';
    case Interlocutor = 'interlocutor';
    case Prospect = 'prospect';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Client',
            self::Supplier => 'Fournisseur',
            self::Internal => 'Interne',
            self::Provider => 'Prestataire',
            self::Carrier => 'Transporteur',
            self::Partner => 'Partenaire',
            self::Interlocutor => 'Interlocuteur',
            self::Prospect => 'Prospect',
        };
    }

    /** Legacy organization_contacts.contact_type -> system role (null = 'other': no role, meaning preserved on the party). */
    public static function fromLegacyType(?string $type): ?self
    {
        return match ($type) {
            'client' => self::Client,
            'supplier' => self::Supplier,
            'internal' => self::Internal,
            'transport' => self::Carrier,
            default => null,
        };
    }
}

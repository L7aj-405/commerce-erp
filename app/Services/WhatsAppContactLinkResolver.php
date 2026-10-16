<?php

namespace App\Services;

use App\Models\OrganizationContact;
use App\Support\PhoneNumber;
use App\Support\WhatsAppUsername;

class WhatsAppContactLinkResolver
{
    /** @return array{available:bool,type:'username'|'phone'|null,destination:string|null,base_url:string|null,link:string|null} */
    public function resolve(?string $username, ?string $phone, ?string $message = null): array
    {
        $normalizedUsername = WhatsAppUsername::normalize($username);
        if (WhatsAppUsername::isValid($normalizedUsername)) {
            return $this->result('username', $normalizedUsername, $message);
        }

        // Compatibility for historical customer/document snapshots where a
        // WhatsApp username was entered in the phone field before the
        // dedicated username column existed. Require the explicit "@" marker
        // so ordinary non-phone text is never reinterpreted as a username.
        $legacyDestination = trim((string) $phone);
        if (str_starts_with($legacyDestination, '@')) {
            $legacyUsername = WhatsAppUsername::normalize($legacyDestination);
            if (WhatsAppUsername::isValid($legacyUsername)) {
                return $this->result('username', $legacyUsername, $message);
            }
        }

        $normalizedPhone = PhoneNumber::forWhatsApp($phone);
        if ($normalizedPhone !== null) {
            return $this->result('phone', $normalizedPhone, $message);
        }

        return [
            'available' => false,
            'type' => null,
            'destination' => null,
            'base_url' => null,
            'link' => null,
        ];
    }

    /** @return array{available:bool,type:'username'|'phone'|null,destination:string|null,base_url:string|null,link:string|null} */
    public function forContact(OrganizationContact $contact, ?string $message = null): array
    {
        return $this->resolve(
            $contact->whatsapp_username,
            $contact->whatsapp ?: $contact->phone,
            $message,
        );
    }

    /** @return array{available:true,type:'username'|'phone',destination:string,base_url:string,link:string} */
    private function result(string $type, string $destination, ?string $message): array
    {
        $baseUrl = 'https://wa.me/'.rawurlencode($destination);

        return [
            'available' => true,
            'type' => $type,
            'destination' => $destination,
            'base_url' => $baseUrl,
            'link' => $message !== null && $message !== ''
                ? $baseUrl.'?'.http_build_query(['text' => $message], '', '&', PHP_QUERY_RFC3986)
                : $baseUrl,
        ];
    }
}

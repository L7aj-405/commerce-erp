<?php

namespace App\Rules;

use App\Support\WhatsAppUsername;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidWhatsAppUsername implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || trim((string) $value) === '') {
            return;
        }

        if (! WhatsAppUsername::isValid((string) $value)) {
            $fail("Le nom d’utilisateur WhatsApp doit contenir 3 à 35 caractères minuscules parmi a-z, 0-9, point ou tiret bas, et ne peut pas être uniquement numérique.");
        }
    }
}

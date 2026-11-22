<?php

namespace App\Services\Procurement;

use App\Models\Supplier;

/**
 * Advisory (never blocking) checks on a supplier's fiscal identity. These are
 * hints to help the user catch a typo; they are not a legal-compliance check
 * and a supplier is always saved whatever they report.
 */
final class SupplierFiscalAdvisor
{
    /** Same identifier ignoring case, spaces, dots, dashes and slashes. */
    public static function normalize(?string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    /** "****1234": enough to recognise an account, never the full number. */
    public static function maskIban(?string $iban): ?string
    {
        $compact = self::normalize($iban);

        return $compact === '' ? null : '****'.substr($compact, -4);
    }

    /** @return list<array{field: string, code: string, message: string}> */
    public function advise(Supplier $supplier): array
    {
        $warnings = [];
        $ice = self::normalize($supplier->ice);
        $identifier = self::normalize($supplier->tax_identifier);

        if ($ice !== '' && preg_match('/^\d{15}$/', $ice) !== 1) {
            $warnings[] = ['field' => 'ice', 'code' => 'ice_format', 'message' => 'Format d’ICE inhabituel (15 chiffres attendus) : vérifiez la saisie. Le fournisseur est enregistré tel quel.'];
        }
        if ($identifier !== '' && preg_match('/^\d{6,10}$/', $identifier) !== 1) {
            $warnings[] = ['field' => 'tax_identifier', 'code' => 'tax_identifier_format', 'message' => 'Format d’identifiant fiscal inhabituel (6 à 10 chiffres attendus) : vérifiez la saisie. Le fournisseur est enregistré tel quel.'];
        }

        if ($ice !== '') {
            $duplicates = Supplier::query()
                ->where('organization_id', $supplier->organization_id)
                ->whereKeyNot($supplier->getKey())
                ->whereNotNull('ice')
                ->get(['id', 'name', 'ice'])
                ->filter(fn (Supplier $other) => self::normalize($other->ice) === $ice)
                ->pluck('name')
                ->values();

            if ($duplicates->isNotEmpty()) {
                $warnings[] = ['field' => 'ice', 'code' => 'ice_duplicate', 'message' => 'Cet ICE est déjà utilisé par : '.$duplicates->take(3)->implode(', ').'. Les doublons sont autorisés ; vérifiez qu’il ne s’agit pas du même fournisseur.'];
            }
        }

        return $warnings;
    }
}

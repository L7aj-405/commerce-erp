<?php

namespace App\Services\Contacts;

use App\Models\Organization;
use App\Models\Party;
use App\Support\NameNormalizer;

/**
 * Identity candidates for a contact being created. Evidence is graded, never conclusive on its own, and nothing is
 * ever merged automatically: the UI offers to open / reuse the candidate or to continue with a genuinely distinct contact.
 *
 *  strong: same ICE, or exactly the same normalized name (and kind);
 *  weak:   same e-mail or same phone (shared numbers and mailboxes are common).
 * The ICE is matched server-side only and is never echoed back.
 */
class PartyDuplicateFinder
{
    /**
     * @param  array{display_name?: ?string, email?: ?string, phone?: ?string, ice?: ?string, kind?: ?string}  $input
     * @return list<array{id: int, display_name: string, kind: string, status: string, roles: list<string>, strength: string, reasons: list<string>}>
     */
    public function find(Organization $organization, array $input, ?int $exceptPartyId = null, int $limit = 8): array
    {
        $orgId = (int) $organization->getKey();
        $name = NameNormalizer::normalize($input['display_name'] ?? '');
        $email = filled($input['email'] ?? null) ? mb_strtolower(trim((string) $input['email'])) : null;
        $phone = $this->digits($input['phone'] ?? null);
        $phone = strlen($phone) >= 7 ? substr($phone, -9) : null;
        $ice = $this->digits($input['ice'] ?? null);
        $ice = strlen($ice) >= 10 ? $ice : null;
        $token = collect(preg_split('/\s+/', $name) ?: [])->filter(fn ($t) => mb_strlen($t) >= 3)->first();

        $query = Party::query()->where('organization_id', $orgId)->when($exceptPartyId, fn ($q) => $q->where('id', '!=', $exceptPartyId))
            ->where(function ($q) use ($token, $email, $phone, $ice) {
                $any = false;
                foreach ([[$token, 'display_name'], [$token, 'legal_name']] as [$value, $column]) {
                    if ($value) {
                        $q->orWhere($column, 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $value).'%');
                        $any = true;
                    }
                }
                if ($email) {
                    $q->orWhere('email', $email);
                    $any = true;
                }
                // Stored numbers are free text ("+212 600-11 22 33"): compare their digits, not their formatting.
                $digits = fn (string $column) => "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '-', ''), '.', ''), '+', ''), '(', ''), ')', '')";
                if ($phone) {
                    $q->orWhereRaw($digits('phone').' like ?', ['%'.$phone.'%']);
                    $any = true;
                }
                if ($ice) {
                    $q->orWhereRaw($digits('ice').' like ?', ['%'.$ice.'%']);
                    $any = true;
                }
                if (! $any) {
                    $q->whereRaw('1 = 0');
                }
            });

        $found = [];
        foreach ($query->limit(60)->get() as $party) {
            $strong = [];
            $weak = [];
            if ($ice && $this->digits($party->ice) === $ice) {
                $strong[] = 'ice';
            }
            if ($name !== '' && in_array($name, [NameNormalizer::normalize($party->display_name), NameNormalizer::normalize($party->legal_name)], true)
                && (($input['kind'] ?? $party->kind) === $party->kind)) {
                $strong[] = 'name';
            }
            if ($email && $party->email === $email) {
                $weak[] = 'email';
            }
            if ($phone && $party->phone && str_ends_with($this->digits($party->phone), $phone)) {
                $weak[] = 'phone';
            }
            if ($strong === [] && $weak === []) {
                continue;
            }
            $found[] = ['party' => $party, 'strength' => $strong !== [] ? 'strong' : 'weak', 'reasons' => [...$strong, ...$weak]];
        }
        usort($found, fn ($a, $b) => [$a['strength'] === 'strong' ? 0 : 1, -count($a['reasons'])] <=> [$b['strength'] === 'strong' ? 0 : 1, -count($b['reasons'])]);
        $found = array_slice($found, 0, $limit);
        $roles = app(PartyDirectory::class)->rolesFor($orgId, array_map(fn ($f) => $f['party']->getKey(), $found));

        return array_map(fn ($f) => [
            'id' => $f['party']->getKey(), 'display_name' => $f['party']->display_name, 'kind' => $f['party']->kind, 'status' => $f['party']->status,
            'roles' => array_map(fn ($r) => $r['name'], $roles[$f['party']->getKey()] ?? []), 'strength' => $f['strength'], 'reasons' => $f['reasons'],
        ], $found);
    }

    private function digits(?string $value): string
    {
        return (string) preg_replace('/\D+/', '', (string) $value);
    }
}

<?php

namespace App\Services\Contacts;

use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\NameNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One reusable, organization-scoped payment terms catalogue shared by customer and supplier profiles.
 *
 * It is DESCRIPTIVE in Phase 1: it records the agreed delay (and a reserved structured convention for deposits /
 * installments) but never creates a due date, schedule or installment. Phase 3 resolves invoice due dates from it
 * (see docs/unified-contacts/PHASE_1_ADR.md); existing invoice and installment due dates are never recalculated.
 */
class PaymentTermCatalogue
{
    public const DEFAULTS = [['Comptant', 0, 'immediate'], ['30 jours', 30, 'net_days'], ['60 jours', 60, 'net_days'], ['90 jours', 90, 'net_days'], ['120 jours', 120, 'net_days']];

    /** Reserved structure keys; validated for shape only and never interpreted yet. */
    private const STRUCTURE_KEYS = ['deposit_percent', 'installments'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** Idempotent: inserts the five standard terms that are missing. */
    public function ensureDefaults(int $organizationId): void
    {
        foreach (self::DEFAULTS as [$name, $days, $kind]) {
            DB::table('payment_terms')->insertOrIgnore([
                'organization_id' => $organizationId, 'name' => $name, 'normalized_name' => NameNormalizer::normalize($name),
                'kind' => $kind, 'days' => $days, 'is_system' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @return list<PaymentTerm> */
    public function list(Organization $organization): array
    {
        $this->ensureDefaults((int) $organization->getKey());

        return PaymentTerm::query()->where('organization_id', $organization->getKey())->where('active', true)->orderBy('days')->orderBy('name')->get()->all();
    }

    public function findForOrganization(int $id, Organization $organization): PaymentTerm
    {
        return PaymentTerm::query()->where('organization_id', $organization->getKey())->where('active', true)->findOrFail($id);
    }

    /**
     * "Custom payment delay": reuse the term of that name or create it, so one custom delay is entered once and reused.
     *
     * @param  array<string, mixed>|null  $structure
     */
    public function findOrCreate(User $actor, Organization $organization, int $days, ?string $name = null, ?array $structure = null): PaymentTerm
    {
        if ($days < 0 || $days > 365) {
            throw ValidationException::withMessages(['days' => 'Le délai doit être compris entre 0 et 365 jours.']);
        }
        $this->ensureDefaults((int) $organization->getKey());
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
        $name = $name !== '' ? $name : ($days === 0 ? 'Comptant' : "{$days} jours");
        if (mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => 'Le nom est limité à 80 caractères.']);
        }
        $normalized = NameNormalizer::normalize($name);
        $structure = $this->validatedStructure($structure);

        return DB::transaction(function () use ($actor, $organization, $days, $name, $normalized, $structure) {
            $find = fn () => PaymentTerm::query()->where('organization_id', $organization->getKey())->where('normalized_name', $normalized)->first();
            $existing = $find();
            if ($existing !== null) {
                if ((int) $existing->days !== $days) {
                    throw ValidationException::withMessages(['name' => "Un terme « {$existing->name} » existe déjà avec un délai de {$existing->days} jours."]);
                }

                return $existing;
            }
            $term = new PaymentTerm;
            $term->organization_id = $organization->getKey();
            $term->name = $name;
            $term->normalized_name = $normalized;
            $term->kind = $days === 0 ? 'immediate' : 'net_days';
            $term->days = $days;
            $term->structure = $structure;
            $term->is_system = false;
            $term->active = true;
            $term->created_by_user_id = $actor->getKey();
            try {
                $term->save();
            } catch (UniqueConstraintViolationException) {
                return $find() ?? throw new \LogicException('Payment term vanished after a unique violation.');
            }
            $this->audit->record('payment_term.created', $actor, $organization, auditable: $term, newValues: ['name' => $name, 'days' => $days]);

            return $term;
        });
    }

    /** @param array<string, mixed>|null $structure  @return array<string, mixed>|null */
    private function validatedStructure(?array $structure): ?array
    {
        if ($structure === null || $structure === []) {
            return null;
        }
        if (array_diff(array_keys($structure), self::STRUCTURE_KEYS) !== []) {
            throw ValidationException::withMessages(['structure' => 'Structure de convention non reconnue.']);
        }
        $deposit = $structure['deposit_percent'] ?? null;
        if ($deposit !== null && (! is_numeric($deposit) || $deposit < 0 || $deposit > 100)) {
            throw ValidationException::withMessages(['structure' => 'L’acompte doit être compris entre 0 et 100 %.']);
        }
        foreach ((array) ($structure['installments'] ?? []) as $installment) {
            if (! is_array($installment) || ! isset($installment['percent'], $installment['days']) || $installment['percent'] <= 0 || $installment['percent'] > 100 || $installment['days'] < 0 || $installment['days'] > 365) {
                throw ValidationException::withMessages(['structure' => 'Chaque échéance requiert un pourcentage (0-100) et un délai (0-365).']);
            }
        }

        return $structure;
    }
}

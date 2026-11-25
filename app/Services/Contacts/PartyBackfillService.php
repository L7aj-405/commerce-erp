<?php

namespace App\Services\Contacts;

use App\Enums\ContactSystemRole;
use App\Enums\CustomerType;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Additive, idempotent, non-destructive backfill of the unified identity from the legacy tables.
 *
 * Guarantees: never deletes or alters a customer / supplier / contact row except its NEW nullable party_id; never
 * changes an id; every row is processed once (rows that already have a party_id are skipped, so re-running is safe).
 *
 * Identity-merge rule (deliberately narrow): two legacy rows become ONE party only when the evidence is unambiguous.
 *   supplier + customer : the same 15-digit ICE, held by exactly one customer and exactly one supplier of the organization.
 *   contact  + account  : the contact is linked to exactly one account, has no job title, bears exactly the account name,
 *                         and no other contact already claimed that account as its identity.
 * Everything else is kept SEPARATE and, when the legacy data hints at a relationship, reported in
 * party_migration_conflicts. A contact linked to a company is an interlocutor of it (party_relationships), never the company.
 * A shared email or name alone never merges anything.
 */
class PartyBackfillService
{
    /** @return array<string, int> counters across the processed organizations */
    public function run(?int $organizationId = null): array
    {
        $report = ['organizations' => 0, 'customers' => 0, 'suppliers' => 0, 'suppliers_merged' => 0, 'contacts' => 0, 'contacts_merged' => 0,
            'relationships' => 0, 'conflicts' => 0, 'terms_linked' => 0];
        $ids = DB::table('organizations')->when($organizationId !== null, fn ($q) => $q->where('id', $organizationId))->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$report) {
                $this->organization((int) $id, $report);
            });
            $report['organizations']++;
        }

        return $report;
    }

    /** @param array<string, int> $report */
    private function organization(int $org, array &$report): void
    {
        app(PaymentTermCatalogue::class)->ensureDefaults($org);
        $this->customers($org, $report);
        $this->suppliers($org, $report);
        $this->contacts($org, $report);
        $this->sharedEmails($org, $report);
        $this->linkStandardTerms($org, $report);
    }

    /** @param array<string, int> $report */
    private function customers(int $org, array &$report): void
    {
        foreach (DB::table('customers')->where('organization_id', $org)->whereNull('party_id')->orderBy('id')->get() as $c) {
            $isCompany = $c->type === CustomerType::Company->value;
            $partyId = $this->insertParty($org, [
                'kind' => $isCompany ? 'organization' : 'person', 'display_name' => $c->display_name, 'legal_name' => $isCompany ? $c->company_name : null,
                'email' => $this->lower($c->email), 'phone' => $c->phone, 'whatsapp_username' => $c->whatsapp_username ?? null,
                'address' => $c->billing_address, 'tax_identifier' => $c->tax_identifier, 'notes' => $c->notes,
                'status' => $c->status === 'active' ? 'active' : 'archived', 'archived_at' => $c->status === 'active' ? null : now(),
            ], $c->created_at);
            DB::table('customers')->where('id', $c->id)->update(['party_id' => $partyId]);
            $this->role($org, $partyId, 'client');
            $report['customers']++;
        }
    }

    /** @param array<string, int> $report */
    private function suppliers(int $org, array &$report): void
    {
        $icesOfSuppliers = DB::table('suppliers')->where('organization_id', $org)->whereNotNull('ice')->pluck('ice')
            ->map(fn ($ice) => $this->digits($ice))->filter(fn ($d) => strlen($d) === 15)->countBy()->all();
        $customerByIce = [];
        foreach (DB::table('customers')->where('organization_id', $org)->whereNotNull('party_id')->whereNotNull('tax_identifier')->get(['id', 'party_id', 'tax_identifier']) as $c) {
            $digits = $this->digits($c->tax_identifier);
            if (strlen($digits) === 15) {
                $customerByIce[$digits][] = $c;
            }
        }

        foreach (DB::table('suppliers')->where('organization_id', $org)->whereNull('party_id')->orderBy('id')->get() as $s) {
            $digits = $this->digits($s->ice);
            $matches = strlen($digits) === 15 ? ($customerByIce[$digits] ?? []) : [];
            $mergeable = count($matches) === 1 && ($icesOfSuppliers[$digits] ?? 0) === 1
                && DB::table('parties')->where('id', $matches[0]->party_id)->value('kind') === 'organization'
                && ! DB::table('suppliers')->where('organization_id', $org)->where('party_id', $matches[0]->party_id)->exists();

            if ($mergeable) {
                $partyId = (int) $matches[0]->party_id;
                $fill = array_filter(['ice' => $s->ice, 'rc_number' => $s->rc_number, 'email' => $this->lower($s->email), 'phone' => $s->phone, 'address' => $s->address], fn ($v) => filled($v));
                foreach ($fill as $column => $value) {
                    DB::table('parties')->where('id', $partyId)->whereNull($column)->update([$column => $value]);
                }
                DB::table('parties')->where('id', $partyId)->update(['kind' => 'organization']);
                $report['suppliers_merged']++;
            } else {
                $partyId = $this->insertParty($org, [
                    'kind' => 'organization', 'display_name' => $s->name, 'email' => $this->lower($s->email), 'phone' => $s->phone, 'address' => $s->address,
                    'ice' => $s->ice, 'tax_identifier' => $s->tax_identifier, 'rc_number' => $s->rc_number, 'notes' => $s->notes,
                    'status' => $s->active ? 'active' : 'archived', 'archived_at' => $s->active ? null : now(),
                ], $s->created_at);
                $this->possibleDuplicate($org, 'supplier', $s->id, $partyId, $s->name, $this->lower($s->email), $report);
                if (strlen($digits) === 15 && count($matches) > 1) {
                    $this->conflict($org, 'ice_shared_by_several_customers', 'supplier', $s->id, 'customer', null, $partyId, 'kept_separate', ['ice' => $s->ice, 'customer_ids' => array_column($matches, 'id')], $report);
                }
            }
            DB::table('suppliers')->where('id', $s->id)->update(['party_id' => $partyId]);
            $this->role($org, $partyId, 'supplier');
            $report['suppliers']++;
        }
    }

    /** @param array<string, int> $report */
    private function contacts(int $org, array &$report): void
    {
        /** @var array<int, true> $claimed parties already adopted as a contact's identity */
        $claimed = DB::table('organization_contacts')->where('organization_id', $org)->whereNotNull('party_id')->pluck('party_id')->flip()->map(fn () => true)->all();

        foreach (DB::table('organization_contacts')->where('organization_id', $org)->whereNull('party_id')->orderBy('id')->get() as $c) {
            $accounts = [];
            if ($c->customer_id) {
                $accounts[] = ['customer', $c->customer_id, DB::table('customers')->where('organization_id', $org)->where('id', $c->customer_id)->first(['party_id', 'display_name'])];
            }
            if ($c->supplier_id) {
                $row = DB::table('suppliers')->where('organization_id', $org)->where('id', $c->supplier_id)->first(['party_id', 'name']);
                $accounts[] = ['supplier', $c->supplier_id, $row ? (object) ['party_id' => $row->party_id, 'display_name' => $row->name] : null];
            }
            $accounts = array_values(array_filter($accounts, fn ($a) => $a[2] !== null && $a[2]->party_id !== null));

            $adopt = count($accounts) === 1 && blank($c->job_title) && ! isset($claimed[$accounts[0][2]->party_id])
                && NameNormalizer::normalize($c->full_name) === NameNormalizer::normalize($accounts[0][2]->display_name);
            if ($adopt) {
                $partyId = (int) $accounts[0][2]->party_id;
                $claimed[$partyId] = true;
                DB::table('organization_contacts')->where('id', $c->id)->update(['party_id' => $partyId]);
                $this->fillMissing($partyId, ['email' => $this->lower($c->email), 'phone' => $c->phone, 'whatsapp' => $c->whatsapp, 'whatsapp_username' => $c->whatsapp_username ?? null]);
                $legacyRole = ContactSystemRole::fromLegacyType($c->contact_type);
                $legacyRole ? $this->role($org, $partyId, $legacyRole->value) : null;
                $report['contacts_merged']++;
                $report['contacts']++;

                continue;
            }

            $partyId = $this->insertParty($org, [
                'kind' => 'person', 'display_name' => $c->full_name, 'email' => $this->lower($c->email), 'phone' => $c->phone, 'whatsapp' => $c->whatsapp,
                'whatsapp_username' => $c->whatsapp_username ?? null, 'notes' => $c->notes,
                'status' => $c->active ? 'active' : 'archived', 'archived_at' => $c->active ? null : ($c->archived_at ?? now()),
                'legacy_contact_type' => ContactSystemRole::fromLegacyType($c->contact_type) === null ? $c->contact_type : null,
            ], $c->created_at);
            DB::table('organization_contacts')->where('id', $c->id)->update(['party_id' => $partyId]);
            $legacyRole = ContactSystemRole::fromLegacyType($c->contact_type);
            $legacyRole ? $this->role($org, $partyId, $legacyRole->value) : null;
            $report['contacts']++;

            foreach ($accounts as [$type, $accountId, $account]) {
                $kind = DB::table('parties')->where('id', $account->party_id)->value('kind');
                if ($kind === 'organization') {
                    $inserted = DB::table('party_relationships')->insertOrIgnore([
                        'organization_id' => $org, 'party_id' => $partyId, 'related_party_id' => $account->party_id, 'type' => 'interlocutor',
                        'job_title' => $c->job_title, 'is_primary' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $report['relationships'] += $inserted;
                }
                $this->conflict($org, 'contact_linked_to_account_not_identical', 'contact', $c->id, $type, $accountId, $partyId,
                    $kind === 'organization' ? 'kept_separate_linked_as_interlocutor' : 'kept_separate', ['contact' => $c->full_name, 'account' => $account->display_name, 'job_title' => $c->job_title], $report);
            }
            if (count($accounts) > 1) {
                $this->conflict($org, 'contact_linked_to_customer_and_supplier', 'contact', $c->id, 'supplier', $c->supplier_id, $partyId, 'kept_separate', ['customer_id' => $c->customer_id, 'supplier_id' => $c->supplier_id], $report);
            }
        }
    }

    /** Same non-empty email on several parties: reported, never merged. @param array<string, int> $report */
    private function sharedEmails(int $org, array &$report): void
    {
        $groups = DB::table('parties')->where('organization_id', $org)->whereNotNull('email')->where('email', '!=', '')->get(['id', 'email'])->groupBy('email');
        foreach ($groups as $email => $rows) {
            if ($rows->count() < 2) {
                continue;
            }
            $first = $rows->first()->id;
            foreach ($rows->skip(1) as $row) {
                $this->conflict($org, 'shared_email_not_merged', 'party', $row->id, 'party', $first, $row->id, 'kept_separate', ['email' => $email], $report);
            }
        }
    }

    /** Link exact standard delays (0/30/60/90/120) to the catalogue; any other delay stays a plain number, untouched. @param array<string, int> $report */
    private function linkStandardTerms(int $org, array &$report): void
    {
        $terms = DB::table('payment_terms')->where('organization_id', $org)->where('is_system', true)->pluck('id', 'days');
        foreach ($terms as $days => $termId) {
            $report['terms_linked'] += DB::table('customer_financial_terms')->where('organization_id', $org)->whereNull('payment_term_id')->where('payment_term_days', $days)->update(['payment_term_id' => $termId]);
            $report['terms_linked'] += DB::table('suppliers')->where('organization_id', $org)->whereNull('payment_term_id')->where('default_payment_term_days', $days)->update(['payment_term_id' => $termId]);
        }
    }

    /** @param array<string, int> $report */
    private function possibleDuplicate(int $org, string $sourceType, int $sourceId, int $partyId, string $name, ?string $email, array &$report): void
    {
        $normalized = NameNormalizer::normalize($name);
        $others = DB::table('parties')->where('organization_id', $org)->where('id', '!=', $partyId)->where('kind', 'organization')->get(['id', 'display_name', 'email']);
        foreach ($others as $other) {
            if (NameNormalizer::normalize($other->display_name) === $normalized || ($email !== null && $email === $other->email)) {
                $this->conflict($org, 'possible_duplicate_identity', $sourceType, $sourceId, 'party', $other->id, $partyId, 'kept_separate', ['name' => $name, 'other' => $other->display_name], $report);
            }
        }
    }

    /** @param array<string, mixed> $values */
    private function insertParty(int $org, array $values, mixed $createdAt): int
    {
        return (int) DB::table('parties')->insertGetId($values + [
            'organization_id' => $org, 'origin' => 'migrated', 'created_at' => $createdAt ?? now(), 'updated_at' => now(),
        ]);
    }

    private function role(int $org, int $partyId, string $systemKey): void
    {
        $roleId = DB::table('contact_roles')->where('organization_scope', 0)->where('system_key', $systemKey)->value('id');
        DB::table('party_roles')->insertOrIgnore(['organization_id' => $org, 'party_id' => $partyId, 'contact_role_id' => $roleId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $values */
    private function fillMissing(int $partyId, array $values): void
    {
        foreach (array_filter($values, fn ($v) => filled($v)) as $column => $value) {
            DB::table('parties')->where('id', $partyId)->whereNull($column)->update([$column => $value]);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, int>  $report
     */
    private function conflict(int $org, string $reason, string $sourceType, int $sourceId, ?string $relatedType, ?int $relatedId, ?int $partyId, string $resolution, array $details, array &$report): void
    {
        $report['conflicts'] += DB::table('party_migration_conflicts')->insertOrIgnore([
            'organization_id' => $org, 'reason' => $reason, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'related_type' => $relatedType ?? '', 'related_id' => $relatedId ?? 0, 'party_id' => $partyId, 'resolution' => $resolution,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function digits(?string $value): string
    {
        return (string) preg_replace('/\D+/', '', (string) $value);
    }

    private function lower(?string $value): ?string
    {
        return filled($value) ? mb_strtolower(trim($value)) : null;
    }
}

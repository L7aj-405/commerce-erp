<?php

namespace App\Actions\Contacts;

use App\Actions\Contacts\Concerns\AuthorizesContactAction;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Contacts\PaymentTermCatalogue;
use App\Services\Sandbox\SandboxContext;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue administration (contacts.payment_terms.manage) and assignment of a term to a commercial profile.
 *
 * A customer and a supplier profile of the SAME party hold independent terms. Assignment keeps the existing legacy
 * day columns in step (customer_financial_terms.payment_term_days / suppliers.default_payment_term_days) so every
 * existing reader keeps working; it never touches credit configuration, the invoicing policy, currency or banking
 * data, and never recalculates an existing invoice or installment due date.
 */
class ManagePaymentTermsAction
{
    use AuthorizesContactAction;

    public function __construct(private readonly PaymentTermCatalogue $catalogue, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed>|null $structure */
    public function create(User $actor, Organization $organization, int $days, ?string $name = null, ?array $structure = null): PaymentTerm
    {
        $this->authorizeContact($actor, $organization, 'contacts.payment_terms.manage');

        return $this->catalogue->findOrCreate($actor, $organization, $days, $name, $structure);
    }

    public function assignToCustomer(User $actor, Customer $customer, ?PaymentTerm $term): void
    {
        $organization = $customer->organization;
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $this->authorizeContact($actor, $organization, 'finance.customers.terms.manage');
        $term = $term === null ? null : $this->catalogue->findForOrganization($term->getKey(), $organization);

        DB::transaction(function () use ($actor, $customer, $organization, $term) {
            $old = DB::table('customer_financial_terms')->where('organization_id', $customer->organization_id)->where('customer_id', $customer->getKey())->lockForUpdate()->first();
            $values = ['payment_term_id' => $term?->getKey()] + ($term !== null ? ['payment_term_days' => $term->days] : []);
            if ($old) {
                DB::table('customer_financial_terms')->where('id', $old->id)->update($values + ['updated_by_user_id' => $actor->getKey(), 'updated_at' => now()]);
            } else {
                DB::table('customer_financial_terms')->insert($values + ['organization_id' => $customer->organization_id, 'customer_id' => $customer->getKey(),
                    'updated_by_user_id' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('customer.payment_term_assigned', $actor, $organization, auditable: $customer,
                oldValues: ['payment_term_id' => $old->payment_term_id ?? null], newValues: ['payment_term_id' => $term?->getKey(), 'days' => $term?->days]);
        });
    }

    public function assignToSupplier(User $actor, Supplier $supplier, ?PaymentTerm $term): void
    {
        $organization = $supplier->organization;
        $this->authorizeContact($actor, $organization, 'suppliers.manage');
        $term = $term === null ? null : $this->catalogue->findForOrganization($term->getKey(), $organization);

        DB::transaction(function () use ($actor, $supplier, $organization, $term) {
            $locked = Supplier::query()->where('organization_id', $organization->getKey())->whereKey($supplier->getKey())->lockForUpdate()->firstOrFail();
            $old = $locked->payment_term_id;
            $locked->payment_term_id = $term?->getKey();
            if ($term !== null) {
                $locked->default_payment_term_days = $term->days;
            }
            $locked->save();
            $this->audit->record('supplier.payment_term_assigned', $actor, $organization, auditable: $locked,
                oldValues: ['payment_term_id' => $old], newValues: ['payment_term_id' => $term?->getKey(), 'days' => $term?->days]);
        });
    }
}

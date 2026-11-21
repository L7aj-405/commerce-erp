<?php

namespace Tests\Feature\Finance;

use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\PosTestCase;

/**
 * Regression tests for R-2 COVERAGE EVIDENCE (not for the arithmetic): a passing R-2 must say which accounts it
 * examined and must disclose accounts that carry posted movements but were NOT examined.
 */
class FinanceReconcileR2CoverageTest extends PosTestCase
{
    public function test_r2_pass_discloses_how_many_accounts_were_checked(): void
    {
        [$organization, $account] = $this->organizationWithPostedPayment();
        $this->openingPosition($organization, $account);

        $r2 = $this->r2($organization);

        $this->assertSame('pass', $r2['status']);
        $this->assertStringContainsString('1 account(s) checked', (string) $r2['note']);
    }

    public function test_r2_discloses_an_inactive_account_that_has_posted_movements_instead_of_claiming_no_opening_position(): void
    {
        [$organization, $account] = $this->organizationWithPostedPayment();
        $this->openingPosition($organization, $account);
        DB::table('financial_accounts')->where('id', $account->id)->update(['status' => 'inactive']);

        $r2 = $this->r2($organization);

        $this->assertNotSame('pass', $r2['status'], 'an unexamined account must never read as a clean PASS');
        $this->assertStringContainsString('not checked', (string) $r2['note']);
        $this->assertStringNotContainsString('No financial account has an opening position', (string) $r2['note'],
            'the account HAS an opening position; the note must state the real reason (inactive)');
    }

    public function test_r2_discloses_an_active_account_without_opening_position_that_has_posted_movements(): void
    {
        [$organization] = $this->organizationWithPostedPayment();

        $r2 = $this->r2($organization);

        $this->assertSame('skipped', $r2['status']);
        $this->assertStringContainsString('posted movement', (string) $r2['note']);
    }

    public function test_a_partial_coverage_pass_states_the_unchecked_accounts_and_keeps_the_verdict(): void
    {
        [$organization, $account] = $this->organizationWithPostedPayment();
        $this->openingPosition($organization, $account);
        $extra = $this->extraAccount($organization, $account, 'active');

        $r2 = $this->r2($organization);

        $this->assertSame('pass', $r2['status'], 'verdict and exit code semantics are unchanged');
        $this->assertSame(0, $r2['violations']);
        $this->assertStringContainsString('1 of 2 account(s) checked', $r2['note']);
        $this->assertStringContainsString('1 account(s) not checked (1 no opening position)', $r2['note']);
        $this->assertStringContainsString('None of them has posted movements', $r2['note']);
        $this->assertStringContainsString('A PASS covers only the checked accounts', $r2['note']);
        $this->assertStringNotContainsString((string) $extra['code'], $r2['note']);
        $this->assertStringNotContainsString((string) $account->code, $r2['note']);
        $this->assertStringNotContainsString((string) $account->name, $r2['note']);
    }

    public function test_a_future_dated_opening_position_is_disclosed(): void
    {
        [$organization, $account] = $this->organizationWithPostedPayment();
        DB::table('financial_account_opening_positions')->insert([
            'organization_id' => $organization->id, 'financial_account_id' => $account->id, 'effective_date' => now()->addDays(30)->toDateString(),
            'amount' => '500.0000', 'currency_code' => $account->currency_code, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $r2 = $this->r2($organization);

        $this->assertSame('skipped', $r2['status']);
        $this->assertStringContainsString('0 of 1 account(s) checked', $r2['note']);
        $this->assertStringContainsString('1 opening position effective after', $r2['note']);
        $this->assertStringContainsString('account id '.$account->id, $r2['note']);
    }

    public function test_full_coverage_is_stated_explicitly(): void
    {
        [$organization, $account] = $this->organizationWithPostedPayment();
        $this->openingPosition($organization, $account);

        $this->assertStringContainsString('All financial accounts were examined', $this->r2($organization)['note']);
    }

    public function test_other_organizations_accounts_never_appear_in_the_coverage_note(): void
    {
        [$organization, $account] = $this->organizationWithPostedPayment();
        $this->openingPosition($organization, $account);
        [, $otherAccount] = $this->organizationWithPostedPayment();
        DB::table('financial_accounts')->where('id', $otherAccount->id)->update(['status' => 'inactive']);

        $r2 = $this->r2($organization);

        $this->assertStringContainsString('1 of 1 account(s) checked', $r2['note']);
        $this->assertStringNotContainsString('inactive', $r2['note']);
    }

    /** @return array<string, mixed> */
    private function extraAccount(Organization $organization, FinancialAccount $like, string $status): array
    {
        $row = ['organization_id' => $organization->id, 'name' => 'Extra Account', 'code' => 'EXTRA-9', 'type' => $like->type, 'status' => $status,
            'currency_code' => $like->currency_code, 'created_at' => now(), 'updated_at' => now()];
        DB::table('financial_accounts')->insert($row);

        return $row;
    }

    /** @return array{Organization, FinancialAccount} */
    private function organizationWithPostedPayment(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'R2 Product', 'R2-1', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '5.0000');
        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [$this->posCatalogLine($variant, ['quantity' => '1.0000'])]))->assertRedirect();
        SalesOrder::query()->where('organization_id', $organization->id)->sole();
        $accountId = DB::table('payments')->where('organization_id', $organization->id)->where('status', 'posted')->value('financial_account_id');
        $this->assertNotNull($accountId, 'fixture must contain a posted payment');

        return [$organization, FinancialAccount::query()->findOrFail($accountId)];
    }

    private function openingPosition(Organization $organization, FinancialAccount $account): void
    {
        DB::table('financial_account_opening_positions')->insert([
            'organization_id' => $organization->id, 'financial_account_id' => $account->id, 'effective_date' => now()->subMonths(2)->toDateString(),
            'amount' => '500.0000', 'currency_code' => $account->currency_code, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function r2(Organization $organization): array
    {
        Artisan::call('finance:reconcile', ['organization' => $organization->id, '--json' => true, '--months' => 3]);

        return collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'])->firstWhere('code', 'R-2');
    }
}

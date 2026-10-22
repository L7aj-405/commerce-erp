<?php

namespace Tests\Feature\Settings;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\OrganizationDataReset\OrganizationDataResetException;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetOperation;
use App\Services\OrganizationDataReset\OrganizationDataResetOperationQuery;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\PlatformTestCase;

class OrganizationDataResetEngineTest extends PlatformTestCase
{
    public function test_preview_counts_are_exact_and_tenant_scoped(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Tenant A');
        $organizationB = $this->createOrganization($ownerB, 'Tenant B');
        $customerA = $this->customer($organizationA, 'Customer A');
        $this->customer($organizationB, 'Customer B');
        $this->contact($organizationA, $customerA);

        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organizationA, OrganizationDataResetScope::Customers);

        $this->assertTrue($plan->isExecutable());
        $this->assertSame(1, $plan->counts['customers.delete']);
        $this->assertSame(1, $plan->counts['contacts.unlink_customer']);
        $this->assertSame(2, $plan->totalAffected());

        app(OrganizationDataResetExecutor::class)->execute($organizationA, $ownerA, $plan);

        $this->assertDatabaseMissing('customers', ['organization_id' => $organizationA->id]);
        $this->assertDatabaseHas('customers', ['organization_id' => $organizationB->id, 'display_name' => 'Customer B']);
        $this->assertDatabaseHas('organization_contacts', [
            'organization_id' => $organizationA->id,
            'customer_id' => null,
        ]);
    }

    public function test_cross_domain_dependency_is_reported_instead_of_silently_expanding_scope(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $customer = $this->customer($organization, 'Referenced customer');
        $this->order($organization, $store->id, $customer->id);

        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organization, OrganizationDataResetScope::Customers);

        $this->assertFalse($plan->isExecutable());
        $this->assertContains('customers.sales_dependencies', array_column($plan->blockers, 'code'));
        $this->assertSame([OrganizationDataResetScope::Customers->value], $plan->scopes);
    }

    public function test_dependency_graph_has_deterministic_child_before_parent_order(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organization, OrganizationDataResetScope::FullOrganization);
        $keys = array_column($plan->operations, 'key');
        $this->assertLessThan(array_search('sales_and_documents.credit_notes', $keys, true), array_search('sales_and_documents.credit_note_lines', $keys, true));
        $this->assertLessThan(array_search('sales_and_documents.invoices', $keys, true), array_search('sales_and_documents.invoice_lines', $keys, true));
        $this->assertLessThan(array_search('sales_and_documents.sales_orders', $keys, true), array_search('sales_and_documents.sales_order_lines', $keys, true));
        $this->assertLessThan(array_search('catalog_and_stock.products', $keys, true), array_search('catalog_and_stock.product_variants', $keys, true));
        $this->assertLessThan(
            array_search('sales_and_documents.sales_order_revisions', $keys, true),
            array_search('sales_orders.unlink_current_revision', $keys, true),
        );
    }

    public function test_users_access_reset_preserves_owner_password_two_factor_security_and_numbering(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $member = User::factory()->create();
        $this->addOrganizationMember($organization, $member, [], roleName: 'Temporary role');
        $this->addStoreMember($store, $member);
        $this->activate($member, $organization, $store);

        $owner->two_factor_secret = 'owner-2fa-secret';
        $owner->two_factor_recovery_codes = ['one', 'two'];
        $owner->two_factor_confirmed_at = now();
        $owner->save();
        $ownerRaw = $owner->fresh()->getRawOriginal();
        $security = $organization->securitySetting()->firstOrFail()->getRawOriginal();

        DB::table('sales_order_sequences')->insert([
            'organization_id' => $organization->id,
            'next_number' => 87,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organization, OrganizationDataResetScope::UsersAndAccess);
        app(OrganizationDataResetExecutor::class)->execute($organization, $owner, $plan);

        $ownerAfter = $owner->fresh()->getRawOriginal();
        foreach (['password', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $column) {
            $this->assertSame($ownerRaw[$column], $ownerAfter[$column]);
        }
        $this->assertSame($security, $organization->securitySetting()->firstOrFail()->getRawOriginal());
        $this->assertDatabaseHas('organization_memberships', ['organization_id' => $organization->id, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('store_memberships', ['organization_id' => $organization->id, 'user_id' => $owner->id]);
        $this->assertDatabaseMissing('organization_memberships', ['organization_id' => $organization->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('users', ['id' => $member->id, 'active_organization_id' => null, 'active_store_id' => null]);
        $this->assertDatabaseHas('sales_order_sequences', ['organization_id' => $organization->id, 'next_number' => 87]);
    }

    public function test_failure_after_mutation_rolls_back_every_operation(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $customer = $this->customer($organization, 'Rollback customer');
        $contact = $this->contact($organization, $customer);
        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organization, OrganizationDataResetScope::Customers);

        $realQueries = new OrganizationDataResetOperationQuery;
        $calls = 0;
        $mock = Mockery::mock(OrganizationDataResetOperationQuery::class);
        $mock->shouldReceive('count')->andReturnUsing(
            fn (Organization $org, OrganizationDataResetOperation $operation) => $realQueries->count($org, $operation),
        );
        $mock->shouldReceive('stateFingerprint')->andReturnUsing(
            fn (Organization $org, OrganizationDataResetOperation $operation) => $realQueries->stateFingerprint($org, $operation),
        );
        $mock->shouldReceive('execute')->andReturnUsing(
            function (Organization $org, OrganizationDataResetOperation $operation) use ($realQueries, &$calls): int {
                $calls++;
                if ($calls === 2) {
                    throw new OrganizationDataResetException('Injected reset failure.');
                }

                return $realQueries->execute($org, $operation);
            },
        );
        $this->app->instance(OrganizationDataResetOperationQuery::class, $mock);

        try {
            $this->app->make(OrganizationDataResetExecutor::class)->execute($organization, $owner, $plan);
            $this->fail('The injected reset failure was not thrown.');
        } catch (OrganizationDataResetException $exception) {
            $this->assertSame('Injected reset failure.', $exception->getMessage());
        }

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('organization_contacts', ['id' => $contact->id, 'customer_id' => $customer->id]);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'event' => 'organization.data_reset.completed']);
    }

    public function test_full_reset_preserves_infrastructure_credentials_audit_and_has_no_orphans(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $customer = $this->customer($organization, 'Disposable customer');
        $order = $this->order($organization, $store->id, $customer->id);
        $contact = $this->contact($organization, $customer);
        $auditCount = DB::table('audit_logs')->where('organization_id', $organization->id)->count();
        $storeCount = DB::table('stores')->where('organization_id', $organization->id)->count();

        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organization, OrganizationDataResetScope::FullOrganization);
        $this->assertTrue($plan->isExecutable());
        $result = app(OrganizationDataResetExecutor::class)->execute($organization, $owner, $plan);

        $this->assertSame(OrganizationDataResetScope::FullOrganization->value, $result->scope);
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'owner_id' => $owner->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertSame($storeCount, DB::table('stores')->where('organization_id', $organization->id)->count());
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('sales_orders', ['id' => $order->id]);
        $this->assertDatabaseHas('organization_contacts', ['id' => $contact->id, 'customer_id' => null]);
        $this->assertSame($auditCount + 1, DB::table('audit_logs')->where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('audit_logs', ['id' => $result->auditLogId, 'event' => 'organization.data_reset.completed']);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertDatabaseHas('store_memberships', ['organization_id' => $organization->id, 'store_id' => $store->id, 'user_id' => $owner->id]);
    }

    public function test_stale_preview_is_rejected_before_mutation(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->customer($organization, 'First customer');
        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organization, OrganizationDataResetScope::Customers);
        $this->customer($organization, 'Late customer');

        $this->expectException(OrganizationDataResetException::class);
        $this->expectExceptionMessage('preview is stale');
        app(OrganizationDataResetExecutor::class)->execute($organization, $owner, $plan);
    }

    private function customer(Organization $organization, string $name): Customer
    {
        $customer = new Customer;
        $customer->organization_id = $organization->id;
        $customer->type = 'individual';
        $customer->display_name = $name;
        $customer->status = 'active';
        $customer->save();

        return $customer;
    }

    private function contact(Organization $organization, Customer $customer): OrganizationContact
    {
        $contact = new OrganizationContact;
        $contact->organization_id = $organization->id;
        $contact->full_name = 'Contact '.$customer->display_name;
        $contact->contact_type = 'client';
        $contact->customer_id = $customer->id;
        $contact->active = true;
        $contact->save();

        return $contact;
    }

    private function order(Organization $organization, int $storeId, int $customerId): SalesOrder
    {
        $order = new SalesOrder;
        $order->organization_id = $organization->id;
        $order->store_id = $storeId;
        $order->customer_id = $customerId;
        $order->order_number = 'SO-RESET-'.uniqid();
        $order->status = 'confirmed';
        $order->currency_code = 'MAD';
        $order->ordered_at = now();
        $order->sale_date = now()->toDateString();
        $order->save();

        return $order;
    }
}

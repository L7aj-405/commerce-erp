<?php

namespace Tests\Feature\Settings;

use App\Models\Customer;
use App\Models\FinanceRecurringObligationTemplate;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\Supplier;
use App\Models\User;
use App\Services\OrganizationDataReset\OrganizationDataResetConfirmation;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use Illuminate\Support\Facades\DB;
use Tests\Support\PlatformTestCase;

class OrganizationDataResetApiTest extends PlatformTestCase
{
    public function test_unauthenticated_preview_is_rejected(): void
    {
        $this->postJson(route('settings.data-reset.preview'), [
            'scope' => OrganizationDataResetScope::Customers->value,
        ])->assertUnauthorized();
    }

    public function test_member_without_permission_cannot_preview_or_execute(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $member);
        $this->activate($member, $organization);

        $this->actingAs($member)->postJson(route('settings.data-reset.preview'), [
            'scope' => OrganizationDataResetScope::Customers->value,
        ])->assertForbidden();

        $this->actingAs($member)->postJson(route('settings.data-reset.execute'), [
            'scope' => OrganizationDataResetScope::Customers->value,
            'fingerprint' => str_repeat('a', 64),
            'confirmation_phrase' => OrganizationDataResetConfirmation::PHRASE,
            'organization_name' => $organization->name,
        ])->assertForbidden();
    }

    public function test_execution_requires_resumable_fresh_authentication_level_two(): void
    {
        [$owner, $organization] = $this->ownerFixture();
        $plan = $this->preview($organization, OrganizationDataResetScope::Customers);

        $this->actingAs($owner)->postJson(route('settings.data-reset.execute'), $this->executionPayload($organization, $plan->fingerprint))
            ->assertStatus(409)
            ->assertJson(['code' => 'FRESH_AUTH_REQUIRED', 'level' => 'L2']);
    }

    public function test_fresh_auth_challenge_and_single_resume_execute_the_reset_exactly_once(): void
    {
        [$owner, $organization] = $this->ownerFixture('Fresh Auth resume tenant');
        $customer = $this->customer($organization, 'Disposable customer');
        $plan = $this->preview($organization, OrganizationDataResetScope::Customers);
        $payload = $this->executionPayload($organization, $plan->fingerprint);

        $this->actingAs($owner)->postJson(route('settings.data-reset.execute'), $payload)
            ->assertStatus(409)
            ->assertJson(['code' => 'FRESH_AUTH_REQUIRED']);

        $this->actingAs($owner)->withFreshAuthentication()->postJson(route('settings.data-reset.execute'), $payload)
            ->assertOk()
            ->assertJsonPath('data.completed', true);

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('organization_id', $organization->id)
            ->where('event', 'organization.data_reset.completed')
            ->count());
    }

    public function test_execute_rate_limit_returns_a_structured_retry_after_response(): void
    {
        [$owner, $organization] = $this->ownerFixture('Rate limited reset tenant');
        $plan = $this->preview($organization, OrganizationDataResetScope::Customers);
        $payload = array_replace($this->executionPayload($organization, $plan->fingerprint), [
            'confirmation_phrase' => 'WRONG CONFIRMATION',
        ]);

        $this->actingAs($owner)->withFreshAuthentication();
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson(route('settings.data-reset.execute'), $payload)
                ->assertUnprocessable();
        }

        $response = $this->postJson(route('settings.data-reset.execute'), $payload)
            ->assertTooManyRequests()
            ->assertJson(['code' => 'DATA_RESET_RATE_LIMITED']);

        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $response->json('retry_after'));
        $this->assertDatabaseMissing('audit_logs', [
            'organization_id' => $organization->id,
            'event' => 'organization.data_reset.completed',
        ]);
    }

    public function test_execution_rejects_wrong_confirmation_phrase_and_organization_name(): void
    {
        [$owner, $organization] = $this->ownerFixture();
        $plan = $this->preview($organization, OrganizationDataResetScope::Customers);

        $this->actingAs($owner)->withFreshAuthentication()->postJson(route('settings.data-reset.execute'), array_replace(
            $this->executionPayload($organization, $plan->fingerprint),
            ['confirmation_phrase' => 'RESET SOMETHING ELSE'],
        ))->assertUnprocessable()->assertJsonValidationErrors('confirmation_phrase');

        $this->actingAs($owner)->withFreshAuthentication()->postJson(route('settings.data-reset.execute'), array_replace(
            $this->executionPayload($organization, $plan->fingerprint),
            ['organization_name' => 'Another organization'],
        ))->assertUnprocessable()->assertJsonValidationErrors('organization_name');
    }

    public function test_stale_preview_and_changed_dependencies_are_rejected_before_mutation(): void
    {
        [$owner, $organization] = $this->ownerFixture();
        $first = $this->customer($organization, 'First customer');
        $plan = $this->preview($organization, OrganizationDataResetScope::Customers);
        $first->display_name = 'Changed after preview with the same record count';
        $first->save();

        $this->actingAs($owner)->withFreshAuthentication()->postJson(
            route('settings.data-reset.execute'),
            $this->executionPayload($organization, $plan->fingerprint),
        )->assertStatus(409)->assertJson(['code' => 'STALE_RESET_PREVIEW']);

        $this->assertDatabaseHas('customers', [
            'id' => $first->id,
            'display_name' => 'Changed after preview with the same record count',
        ]);
    }

    public function test_cross_tenant_fingerprint_and_forged_organization_id_cannot_target_another_tenant(): void
    {
        [$ownerA, $organizationA] = $this->ownerFixture('Tenant A');
        $ownerB = User::factory()->create();
        $organizationB = $this->createOrganization($ownerB, 'Tenant B');
        $customerB = $this->customer($organizationB, 'Tenant B customer');
        $foreignPlan = $this->preview($organizationB, OrganizationDataResetScope::Customers);
        $this->activate($ownerA, $organizationA);

        $this->actingAs($ownerA)->withFreshAuthentication()->postJson(route('settings.data-reset.execute'), array_merge(
            $this->executionPayload($organizationA, $foreignPlan->fingerprint),
            ['organization_id' => $organizationB->id],
        ))->assertUnprocessable()->assertJsonValidationErrors('organization_id');

        $this->assertDatabaseHas('customers', ['id' => $customerB->id, 'organization_id' => $organizationB->id]);
    }

    public function test_authorized_admin_can_preview_and_execute_a_valid_reset(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $organization = $this->createOrganization($owner, 'Admin reset tenant');
        $this->addOrganizationMember($organization, $admin, ['system.data_reset'], roleName: 'Data Reset Administrator');
        $this->activate($admin, $organization);
        $customer = $this->customer($organization, 'Disposable customer');
        $contact = new OrganizationContact;
        $contact->organization_id = $organization->id;
        $contact->customer_id = $customer->id;
        $contact->full_name = 'Preserved address-book contact';
        $contact->contact_type = 'client';
        $contact->active = true;
        $contact->save();

        $preview = $this->actingAs($admin)->postJson(route('settings.data-reset.preview'), [
            'scope' => OrganizationDataResetScope::Customers->value,
        ])->assertOk()
            ->assertJsonPath('data.executable', true)
            ->assertJsonMissingPath('data.operations')
            ->assertJsonMissingPath('data.tables')
            ->json('data');
        $this->assertSame(2, $preview['affected_records']);

        $response = $this->actingAs($admin)->withFreshAuthentication()->postJson(route('settings.data-reset.execute'), [
            'scope' => OrganizationDataResetScope::Customers->value,
            'fingerprint' => $preview['fingerprint'],
            'confirmation_phrase' => $preview['confirmation_phrase'],
            'organization_name' => $organization->name,
        ])->assertOk()->assertJsonPath('data.completed', true);
        $this->assertSame($preview['affected_records'], $response->json('data.affected_records'));

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('organization_contacts', ['id' => $contact->id, 'customer_id' => null]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_id' => $admin->id,
            'event' => 'organization.data_reset.completed',
        ]);
    }

    public function test_same_zero_effect_preview_cannot_be_executed_twice_and_audits_exactly_once(): void
    {
        [$owner, $organization] = $this->ownerFixture('Idempotent reset tenant');
        $preview = $this->actingAs($owner)->postJson(route('settings.data-reset.preview'), [
            'scope' => OrganizationDataResetScope::Customers->value,
        ])->assertOk()->json('data');
        $this->assertSame(0, $preview['affected_records']);
        $payload = [
            'scope' => OrganizationDataResetScope::Customers->value,
            'fingerprint' => $preview['fingerprint'],
            'confirmation_phrase' => $preview['confirmation_phrase'],
            'organization_name' => $organization->name,
        ];

        $this->actingAs($owner)->withFreshAuthentication()->postJson(route('settings.data-reset.execute'), $payload)
            ->assertOk();
        $this->actingAs($owner)->withFreshAuthentication()->postJson(route('settings.data-reset.execute'), $payload)
            ->assertStatus(409)
            ->assertJson(['code' => 'RESET_ALREADY_EXECUTED']);

        $this->assertSame(1, DB::table('audit_logs')
            ->where('organization_id', $organization->id)
            ->where('event', 'organization.data_reset.completed')
            ->count());
    }

    public function test_supplier_reset_stays_blocked_but_full_reset_unlinks_preserved_recurring_template(): void
    {
        [$owner, $organization] = $this->ownerFixture('Recurring supplier tenant');
        $supplier = new Supplier;
        $supplier->organization_id = $organization->id;
        $supplier->name = 'Recurring supplier';
        $supplier->active = true;
        $supplier->save();
        $template = $this->recurringTemplate($organization, $owner, $supplier);

        $targeted = $this->actingAs($owner)->postJson(route('settings.data-reset.preview'), [
            'scope' => OrganizationDataResetScope::SuppliersAndPurchasing->value,
        ])->assertOk()->json('data');
        $this->assertFalse($targeted['executable']);
        $this->assertContains('suppliers.recurring_configuration', array_column($targeted['blockers'], 'code'));

        $full = $this->actingAs($owner)->postJson(route('settings.data-reset.preview'), [
            'scope' => OrganizationDataResetScope::FullOrganization->value,
        ])->assertOk()->json('data');
        $this->assertTrue($full['executable']);

        $this->actingAs($owner)->withFreshAuthentication()->postJson(route('settings.data-reset.execute'), [
            'scope' => OrganizationDataResetScope::FullOrganization->value,
            'fingerprint' => $full['fingerprint'],
            'confirmation_phrase' => $full['confirmation_phrase'],
            'organization_name' => $organization->name,
        ])->assertOk();

        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
        $this->assertDatabaseHas('finance_recurring_obligation_templates', [
            'id' => $template->id,
            'organization_id' => $organization->id,
            'supplier_id' => null,
        ]);
    }

    /** @return array{User,Organization} */
    private function ownerFixture(string $name = 'Reset tenant'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner, $name);
        $this->activate($owner, $organization);

        return [$owner, $organization];
    }

    private function preview(Organization $organization, OrganizationDataResetScope $scope): \App\Services\OrganizationDataReset\OrganizationDataResetPlan
    {
        return app(OrganizationDataResetPreviewService::class)->preview($organization, $scope);
    }

    /** @return array<string,string> */
    private function executionPayload(Organization $organization, string $fingerprint): array
    {
        return [
            'scope' => OrganizationDataResetScope::Customers->value,
            'fingerprint' => $fingerprint,
            'confirmation_phrase' => OrganizationDataResetConfirmation::PHRASE,
            'organization_name' => $organization->name,
        ];
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

    private function recurringTemplate(Organization $organization, User $owner, Supplier $supplier): FinanceRecurringObligationTemplate
    {
        $template = new FinanceRecurringObligationTemplate;
        $template->organization_id = $organization->id;
        $template->template_number = 'REC-RESET-001';
        $template->category = 'rent';
        $template->label = 'Preserved recurring template';
        $template->supplier_id = $supplier->id;
        $template->planned_amount = '500.0000';
        $template->currency_code = 'MAD';
        $template->due_day_mode = 'fixed';
        $template->due_day = 15;
        $template->start_date = '2026-10-01';
        $template->active = true;
        $template->created_by_user_id = $owner->id;
        $template->save();

        return $template;
    }
}

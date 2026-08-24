<?php

namespace Tests\Feature\Security\TenantRedTeam;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;

class LifecycleAuditAndSubmissionAttackTest extends TenantRedTeamTestCase
{
    public function test_inactive_foreign_records_cannot_be_discovered_or_revived_outside_active_context(): void
    {
        $this->productB->status = 'inactive';
        $this->productB->save();
        $this->variantB->status = 'inactive';
        $this->variantB->save();
        $this->brandB->status = 'inactive';
        $this->brandB->save();

        $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('catalog.products.show', $this->productB))
            ->assertNotFound();
        $this->actingAs($this->userA)
            ->patch(route('catalog.products.update', $this->productB), [
                'name' => 'Revived Foreign Product',
                'status' => 'active',
            ])
            ->assertNotFound();
        $this->actingAs($this->userA)
            ->patch(route('catalog.brands.update', $this->brandB), [
                'name' => 'Revived Foreign Brand',
                'slug' => 'revived-foreign-brand',
                'status' => 'active',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('products', ['id' => $this->productB->id, 'status' => 'inactive']);
        $this->assertDatabaseHas('product_variants', ['id' => $this->variantB->id, 'status' => 'inactive']);
        $this->assertDatabaseHas('brands', ['id' => $this->brandB->id, 'status' => 'inactive']);
    }

    public function test_rejected_attack_creates_no_audit_entry_or_foreign_secret_payload(): void
    {
        $auditCount = AuditLog::query()->count();

        $response = $this->actingAs($this->userA)
            ->postJson(route('catalog.products.store'), $this->productPayload([
                'name' => 'Rejected Audit Attack',
                'brand_id' => $this->brandC->id,
                'password' => 'attack-password-secret',
                'token' => 'attack-token-secret',
                'variant' => ['sku' => 'REJECTED-AUDIT-ATTACK'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('brand_id');

        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertDatabaseMissing('products', ['name' => 'Rejected Audit Attack']);
        $this->assertResponseDoesNotContain($response, [
            'RedTeam Brand C', 'RedTeam Organization C',
            'attack-password-secret', 'attack-token-secret',
        ]);

        $organizationAAuditPayload = AuditLog::query()
            ->where('organization_id', $this->organizationA->id)
            ->get(['old_values', 'new_values'])
            ->toJson();
        $this->assertStringNotContainsString('RedTeam Product B', $organizationAAuditPayload);
        $this->assertStringNotContainsString('RedTeam Product C', $organizationAAuditPayload);
        $this->assertStringNotContainsString('attack-password-secret', $organizationAAuditPayload);
        $this->assertStringNotContainsString('attack-token-secret', $organizationAAuditPayload);
    }

    public function test_duplicate_submissions_do_not_cross_tenants_or_reassign_ownership(): void
    {
        foreach (range(1, 2) as $attempt) {
            $this->actingAs($this->userA)->post(route('organizations.store'), [
                'name' => 'Duplicate Organization Name',
                'owner_id' => $this->userB->id,
                'organization_id' => $this->organizationC->id,
                'attempt' => $attempt,
            ])->assertRedirect(route('platform.index'));
        }

        $duplicateOrganizations = Organization::query()->where('name', 'Duplicate Organization Name')->get();
        $this->assertCount(2, $duplicateOrganizations);
        $this->assertTrue($duplicateOrganizations->every(fn (Organization $organization) => $organization->owner_id === $this->userA->id));
        $this->assertSame(2, $this->userA->organizationMemberships()->whereIn('organization_id', $duplicateOrganizations->modelKeys())->count());

        $this->actingAs($this->userA)->post(route('stores.store'), [
            'name' => 'Duplicate Store First', 'code' => 'DUPLICATE-CODE',
        ])->assertRedirect();
        $this->actingAs($this->userA)->postJson(route('stores.store'), [
            'name' => 'Duplicate Store Second', 'code' => 'DUPLICATE-CODE',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->actingAs($this->userA)->post(route('catalog.products.store'), $this->productPayload([
            'name' => 'Duplicate Product First',
            'variant' => ['sku' => 'DUPLICATE-PRODUCT-SKU'],
        ]))->assertRedirect();
        $this->actingAs($this->userA)->postJson(route('catalog.products.store'), $this->productPayload([
            'name' => 'Duplicate Product Second',
            'variant' => ['sku' => 'DUPLICATE-PRODUCT-SKU'],
        ]))->assertUnprocessable()->assertJsonValidationErrors('variant.sku');

        $this->assertSame(1, Store::query()->where('organization_id', $this->organizationA->id)->where('code', 'DUPLICATE-CODE')->count());
        $this->assertSame(1, ProductVariant::query()->where('organization_id', $this->organizationA->id)->where('sku', 'DUPLICATE-PRODUCT-SKU')->count());
        $this->assertDatabaseMissing('products', [
            'organization_id' => $this->organizationA->id,
            'name' => 'Duplicate Product Second',
        ]);
        $this->assertSame(0, Product::query()->whereIn('organization_id', [$this->organizationB->id, $this->organizationC->id])->where('name', 'like', 'Duplicate Product%')->count());
    }

    public function test_unauthorized_error_response_does_not_disclose_foreign_names_or_debug_details(): void
    {
        $response = $this->actingAs($this->userB)
            ->getJson(route('catalog.products.show', $this->productA))
            ->assertNotFound();

        $this->assertResponseDoesNotContain($response, [
            'RedTeam Product A', 'RedTeam Organization A', 'RED-SKU-A',
            'SQLSTATE', 'vendor/laravel', 'vendor\\laravel',
            'C:\\Users\\toshiba', '/home/', 'DB_PASSWORD',
        ]);
    }
}

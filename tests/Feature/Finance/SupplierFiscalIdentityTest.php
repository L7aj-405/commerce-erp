<?php

namespace Tests\Feature\Finance;

use App\Actions\Procurement\SaveSupplierAction;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Mcp\Procurement\SupplierReadService;
use App\Services\Procurement\SupplierFiscalAdvisor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\SalesTestCase;

/**
 * Phase 2 Sprint 1 (PR1): optional fiscal identity of a supplier (ICE, IF, RC,
 * default term / currency, IBAN). Format hints are advisory and never block;
 * the IBAN is never listed, never in MCP reads and always masked in audit.
 */
class SupplierFiscalIdentityTest extends SalesTestCase
{
    private const ICE = '001234567000089';

    private const IBAN = 'MA64011519000001205000534921';

    /** @return array{User, Organization} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);

        return [$owner, $organization];
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Fournisseur SARL', 'contact_person' => null, 'phone' => null, 'email' => null, 'address' => null, 'notes' => null,
            'active' => true, 'ice' => self::ICE, 'tax_identifier' => '12345678', 'rc_number' => 'RC 4521',
            'default_payment_term_days' => 60, 'default_currency_code' => 'mad', 'bank_iban' => 'MA64 0115 1900 0001 2050 0053 4921',
        ], $overrides);
    }

    public function test_migration_adds_nullable_columns_and_legacy_suppliers_stay_valid(): void
    {
        foreach (['ice', 'tax_identifier', 'rc_number', 'default_payment_term_days', 'default_currency_code', 'bank_iban'] as $column) {
            $this->assertTrue(Schema::hasColumn('suppliers', $column), $column);
        }
        [, $organization] = $this->fixture();
        $legacy = new Supplier;
        $legacy->organization_id = $organization->id;
        $legacy->name = 'Legacy';
        $legacy->active = true;
        $legacy->save();

        $row = DB::table('suppliers')->where('id', $legacy->id)->first();
        $this->assertNull($row->ice);
        $this->assertNull($row->bank_iban);
        $this->assertNull($row->default_payment_term_days);
    }

    public function test_migration_down_refuses_when_a_supplier_carries_fiscal_data(): void
    {
        [$owner, $organization] = $this->fixture();
        app(SaveSupplierAction::class)->execute($owner, $organization, $this->payload());
        $migration = require base_path('database/migrations/2026_11_26_000001_add_fiscal_identity_to_suppliers.php');

        try {
            $migration->down();
            $this->fail('down() must refuse while fiscal identity data exists.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to drop supplier fiscal identity columns', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('suppliers', 'ice'));
    }

    public function test_action_saves_normalizes_and_preserves_fields_omitted_from_the_payload(): void
    {
        [$owner, $organization] = $this->fixture();
        $supplier = app(SaveSupplierAction::class)->execute($owner, $organization, $this->payload());
        $supplier = $supplier->fresh();

        $this->assertSame(self::ICE, $supplier->ice);
        $this->assertSame('RC 4521', $supplier->rc_number);
        $this->assertSame(60, $supplier->default_payment_term_days);
        $this->assertSame('MAD', $supplier->default_currency_code);
        $this->assertSame(self::IBAN, $supplier->bank_iban);

        // MCP-style payload: no fiscal keys at all -> nothing is wiped.
        app(SaveSupplierAction::class)->execute($owner, $organization, ['name' => 'Renommé', 'active' => true], $supplier);
        $kept = $supplier->fresh();
        $this->assertSame('Renommé', $kept->name);
        $this->assertSame([self::ICE, 60, self::IBAN], [$kept->ice, $kept->default_payment_term_days, $kept->bank_iban]);

        // An explicit null clears.
        app(SaveSupplierAction::class)->execute($owner, $organization, ['name' => 'Renommé', 'active' => true, 'ice' => null, 'bank_iban' => null], $kept);
        $cleared = $supplier->fresh();
        $this->assertNull($cleared->ice);
        $this->assertNull($cleared->bank_iban);
        $this->assertSame(60, $cleared->default_payment_term_days);
    }

    public function test_validation_checks_length_and_charset_only(): void
    {
        [$owner, $organization] = $this->fixture();
        $this->actingAs($owner);
        $url = '/procurement/suppliers';

        $this->post($url, $this->payload(['ice' => str_repeat('1', 33)]))->assertSessionHasErrors('ice');
        $this->post($url, $this->payload(['ice' => '<script>']))->assertSessionHasErrors('ice');
        $this->post($url, $this->payload(['tax_identifier' => 'ab#']))->assertSessionHasErrors('tax_identifier');
        $this->post($url, $this->payload(['default_payment_term_days' => -1]))->assertSessionHasErrors('default_payment_term_days');
        $this->post($url, $this->payload(['default_payment_term_days' => 400]))->assertSessionHasErrors('default_payment_term_days');
        $this->post($url, $this->payload(['default_currency_code' => 'MADX']))->assertSessionHasErrors('default_currency_code');
        $this->post($url, $this->payload(['bank_iban' => 'MA64-0115']))->assertSessionHasErrors('bank_iban');
        $this->assertSame(0, Supplier::query()->where('organization_id', $organization->id)->count());

        // A malformed-looking but charset-valid ICE is accepted (advisory only).
        $this->post($url, $this->payload(['ice' => '123']))->assertSessionHasNoErrors();
        $this->assertSame('123', Supplier::query()->where('organization_id', $organization->id)->value('ice'));
    }

    public function test_format_hints_and_duplicate_ice_are_advisory_never_blocking(): void
    {
        [$owner, $organization] = $this->fixture();
        $this->actingAs($owner);

        $this->post('/procurement/suppliers', $this->payload(['ice' => '123', 'tax_identifier' => 'AB12']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('supplier_warnings', fn (array $warnings) => collect($warnings)->pluck('code')->sort()->values()->all() === ['ice_format', 'tax_identifier_format']);
        $this->assertStringContainsString('Attention', (string) session('success'));

        // A well-formed ICE: no format warning.
        $this->post('/procurement/suppliers', $this->payload(['name' => 'Bon format', 'ice' => self::ICE, 'tax_identifier' => '12345678']))
            ->assertSessionHas('supplier_warnings', []);

        // Same ICE (ignoring spaces) on another supplier: saved, with a duplicate warning naming the other one.
        $this->post('/procurement/suppliers', $this->payload(['name' => 'Doublon', 'ice' => '001234567 000089']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('supplier_warnings', fn (array $warnings) => collect($warnings)->pluck('code')->contains('ice_duplicate')
                && str_contains(collect($warnings)->firstWhere('code', 'ice_duplicate')['message'], 'Bon format'));
        $this->assertSame(3, Supplier::query()->where('organization_id', $organization->id)->count());
    }

    public function test_duplicate_ice_hint_is_organization_scoped(): void
    {
        [$owner, $organization] = $this->fixture();
        [$otherOwner, $otherOrganization] = $this->fixture();
        app(SaveSupplierAction::class)->execute($otherOwner, $otherOrganization, $this->payload(['name' => 'Autre org']));
        $mine = app(SaveSupplierAction::class)->execute($owner, $organization, $this->payload(['name' => 'Mon fournisseur']));

        $this->assertSame([], app(SupplierFiscalAdvisor::class)->advise($mine));
    }

    public function test_iban_is_masked_in_audit_and_never_stored_in_clear_there(): void
    {
        [$owner, $organization] = $this->fixture();
        $supplier = app(SaveSupplierAction::class)->execute($owner, $organization, $this->payload());
        app(SaveSupplierAction::class)->execute($owner, $organization, $this->payload(['bank_iban' => 'MA64 0000 0000 0000 0000 0000 9999', 'ice' => '999']), $supplier);

        $logs = AuditLog::query()->whereIn('event', ['supplier.created', 'supplier.updated'])->where('auditable_id', $supplier->id)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('****4921', $logs[0]->new_values['bank_iban']);
        $this->assertSame(self::ICE, $logs[0]->new_values['ice']);
        $this->assertSame('****9999', $logs[1]->new_values['bank_iban']);
        $this->assertSame('****4921', $logs[1]->old_values['bank_iban']);
        $this->assertSame(self::ICE, $logs[1]->old_values['ice']);

        $raw = json_encode(AuditLog::query()->where('organization_id', $organization->id)->get()->toArray());
        $this->assertStringNotContainsString('MA64011519', $raw);
        $this->assertStringNotContainsString('0000000000000000', $raw);
    }

    public function test_index_and_show_expose_fiscal_fields_but_the_iban_only_where_authorized(): void
    {
        [$owner, $organization] = $this->fixture();
        $supplier = app(SaveSupplierAction::class)->execute($owner, $organization, $this->payload());

        // Index: fiscal identity yes, IBAN never (only a boolean).
        $this->actingAs($owner)->get('/procurement/suppliers')->assertOk()->assertInertia(fn ($page) => $page
            ->where('suppliers.data.0.ice', self::ICE)
            ->where('suppliers.data.0.tax_identifier', '12345678')
            ->where('suppliers.data.0.rc_number', 'RC 4521')
            ->where('suppliers.data.0.default_payment_term_days', 60)
            ->where('suppliers.data.0.default_currency_code', 'MAD')
            ->where('suppliers.data.0.has_bank_iban', true)
            ->missing('suppliers.data.0.bank_iban'));

        // Show for a manager: full IBAN + masked + warnings list.
        $this->actingAs($owner)->get("/procurement/suppliers/{$supplier->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('supplier.bank_iban', self::IBAN)
            ->where('supplier.bank_iban_masked', '****4921')
            ->where('supplier.has_bank_iban', true)
            ->where('supplier.ice', self::ICE)
            ->where('warnings', []));

        // Show for a read-only user: masked IBAN only, the full number never leaves the server.
        $viewer = User::factory()->create();
        $this->addOrganizationMember($organization, $viewer, ['suppliers.view']);
        $this->activate($viewer, $organization);
        $response = $this->actingAs($viewer)->get("/procurement/suppliers/{$supplier->id}")->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('supplier.bank_iban', null)
            ->where('supplier.bank_iban_masked', '****4921')
            ->where('supplier.ice', self::ICE));
        $this->assertStringNotContainsString(self::IBAN, $response->getContent());

        // A user without suppliers.view sees none of it.
        $outsider = User::factory()->create();
        $this->addOrganizationMember($organization, $outsider, ['catalog.view']);
        $this->activate($outsider, $organization);
        $this->actingAs($outsider)->get("/procurement/suppliers/{$supplier->id}")->assertForbidden();
    }

    public function test_update_through_the_web_form_keeps_the_iban_when_the_key_is_absent(): void
    {
        [$owner, $organization] = $this->fixture();
        $supplier = app(SaveSupplierAction::class)->execute($owner, $organization, $this->payload());
        $payload = $this->payload(['name' => 'Renommé web']);
        unset($payload['bank_iban']);

        $this->actingAs($owner)->patch("/procurement/suppliers/{$supplier->id}", $payload)->assertSessionHasNoErrors();
        $fresh = $supplier->fresh();
        $this->assertSame('Renommé web', $fresh->name);
        $this->assertSame(self::IBAN, $fresh->bank_iban);
    }

    public function test_supplier_model_never_serializes_the_iban(): void
    {
        [$owner, $organization] = $this->fixture();
        $supplier = app(SaveSupplierAction::class)->execute($owner, $organization, $this->payload())->fresh();

        $this->assertArrayNotHasKey('bank_iban', $supplier->toArray());
        $this->assertStringNotContainsString(self::IBAN, $supplier->toJson());
    }

    public function test_mcp_supplier_reads_do_not_leak_any_fiscal_identity_field(): void
    {
        [$owner, $organization] = $this->fixture();
        $supplier = app(SaveSupplierAction::class)->execute($owner, $organization, $this->payload());
        $service = app(SupplierReadService::class);

        $outputs = [
            $service->supplier($organization, $supplier->id),
            $service->summary($supplier->fresh()),
            $service->search($organization, ['query' => 'Fournisseur']),
        ];
        $json = json_encode($outputs);

        foreach (['ice', 'tax_identifier', 'rc_number', 'default_payment_term_days', 'default_currency_code', 'bank_iban'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'"', $json, $field);
        }
        foreach ([self::ICE, '12345678', 'RC 4521', self::IBAN] as $value) {
            $this->assertStringNotContainsString($value, $json, $value);
        }
    }

    public function test_action_validation_rules_are_the_shared_contract(): void
    {
        $rules = SaveSupplierAction::rules();
        foreach (['ice', 'tax_identifier', 'rc_number', 'default_payment_term_days', 'default_currency_code', 'bank_iban'] as $field) {
            $this->assertArrayHasKey($field, $rules);
            $this->assertContains('nullable', $rules[$field]);
        }
        $this->expectException(ValidationException::class);
        validator(['name' => 'X', 'ice' => 'bad value!'], $rules)->validate();
    }
}

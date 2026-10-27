<?php

namespace Tests\Feature\Procurement;

use App\Actions\Quotations\CancelQuotationSupplierRequestAction;
use App\Actions\Quotations\CreateQuotationSupplierRequestAction;
use App\Actions\Quotations\IssueQuotationAction;
use App\Actions\Quotations\RecordQuotationSupplierAvailabilityAction;
use App\Enums\QuotationSupplierAvailabilityStatus as Status;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\QuotationSourcing;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

class QuotationSupplierRequestInboxTest extends QuotationTestCase
{
    private const PAGE = 'Procurement/SupplierRequests/Index';

    private User $owner;

    private Organization $organization;

    private Store $store;

    private ProductVariant $variant;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00'));

        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->organization, $this->owner);
        $this->variant = $this->createProduct($this->organization, 'Micro Shure', 'MIC-INBOX', ['default_sale_price' => '1000.0000'])
            ->variants->firstOrFail();
        $this->activate($this->owner, $this->organization, $this->store);
        $this->supplier = $this->supplier($this->organization, 'Audio Pro Distribution');
    }

    public function test_the_default_inbox_lists_pending_and_partial_requests_of_the_active_store(): void
    {
        $pending = $this->pendingRequest();
        $partial = $this->pendingRequest(quantity: '6');
        $this->record($partial, 'available', '2');
        $available = $this->pendingRequest();
        $this->record($available, 'available', '5');

        $this->inbox()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component(self::PAGE)
                ->where('filters.status', 'open')
                ->has('requests.data', 2)
                ->where('requests.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$pending->id, $partial->id])->sort()->values()->all())
                ->where('requests.data.0.supplier_name', 'Audio Pro Distribution'));

        // Other statuses are not hidden permanently.
        $this->inbox(['status' => 'all'])->assertInertia(fn (Assert $page) => $page->has('requests.data', 3));
        $this->inbox(['status' => 'available'])->assertInertia(fn (Assert $page) => $page
            ->has('requests.data', 1)->where('requests.data.0.id', $available->id));
    }

    public function test_every_status_renders_with_its_canonical_value_and_manual_rows_are_internal(): void
    {
        $this->pendingRequest();
        $this->record($this->pendingRequest(quantity: '6'), 'available', '2');
        $this->record($this->pendingRequest(), 'available', '5');
        $this->record($this->pendingRequest(), 'unavailable');
        app(CancelQuotationSupplierRequestAction::class)->execute($this->owner, ...$this->quotationAndRequest($this->pendingRequest()));

        // Manual confirmation: issue with override, no prior request.
        [$manualQuotation] = $this->shortageQuotation('3');
        app(IssueQuotationAction::class)->execute($this->owner, $manualQuotation->fresh(), true);

        $this->inbox(['status' => 'all'])->assertInertia(fn (Assert $page) => $page
            ->has('requests.data', 6)
            ->where('requests.data', function ($rows) {
                $rows = collect($rows);
                $statuses = $rows->pluck('status')->sort()->values()->all();
                $manual = $rows->firstWhere('status', 'confirmed_manually');

                return $statuses === ['available', 'cancelled', 'confirmed_manually', 'partial', 'pending', 'unavailable']
                    && $manual['supplier_name'] === null
                    && $manual['confirmed_quantity'] === '3.0000'
                    && $manual['quotation']['is_draft'] === false
                    && $manual['quotation']['number'] !== null;
            }));
    }

    public function test_rows_link_only_to_the_requests_own_quotation_and_customer(): void
    {
        $customer = $this->createCustomer($this->organization, 'Client Inbox', ['company_name' => 'INBOX SARL']);
        [$quotation, $line] = $this->shortageQuotation('4', ['customer_id' => $customer->id]);
        $request = $this->requestSupplier($quotation, $line);

        $this->inbox()->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.id', $request->id)
            ->where('requests.data.0.quotation.id', $quotation->id)
            ->where('requests.data.0.quotation.number', null)
            ->where('requests.data.0.quotation.is_draft', true)
            ->where('requests.data.0.customer.id', $customer->id)
            ->where('requests.data.0.customer.name', 'INBOX SARL')
            ->where('can.viewCustomers', true));

        // A purchasing user without customers.view gets no customer link permission.
        $buyer = $this->member(['quotations.view', 'procurement.view', 'suppliers.view']);
        $this->actingAs($buyer)->get(route('procurement.supplier-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.viewCustomers', false)->where('can.record', false)->where('can.cancel', false));
    }

    public function test_non_stock_articles_render_with_their_own_identity(): void
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->store);
        $line = $this->addNonStockQuotationLine($this->owner, $quotation, ['name' => 'Clavier XYZ Pro', 'reference' => 'XYZ-2026', 'quantity' => '1']);
        $this->requestSupplier($quotation, $line);

        $this->inbox()->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.article.line_type', 'non_stock')
            ->where('requests.data.0.article.name', 'Clavier XYZ Pro')
            ->where('requests.data.0.article.reference', 'XYZ-2026'));
    }

    public function test_recording_a_response_from_the_inbox_updates_the_canonical_request_and_the_quotation_sourcing(): void
    {
        $request = $this->pendingRequest(quantity: '4');

        $this->actingAs($this->owner)
            ->from(route('procurement.supplier-requests.index'))
            ->patch(route('quotations.sourcing.record', [$request->quotation_id, $request->id]), ['outcome' => 'available', 'confirmed_quantity' => '4'])
            ->assertRedirect(route('procurement.supplier-requests.index'));

        $this->assertSame(Status::Available, $request->fresh()->status);
        $this->assertSame(1, QuotationSupplierAvailabilityRequest::query()->count());
        $evaluation = app(QuotationSourcing::class)->evaluate(Quotation::query()->findOrFail($request->quotation_id));
        $this->assertSame('available', $evaluation[$request->quotation_line_id]['state']);

        // It leaves the default "open" inbox.
        $this->inbox()->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));
    }

    public function test_cancelling_from_the_inbox_uses_the_canonical_action(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->owner)
            ->from(route('procurement.supplier-requests.index'))
            ->post(route('quotations.sourcing.cancel', [$request->quotation_id, $request->id]))
            ->assertRedirect(route('procurement.supplier-requests.index'));

        $this->assertSame(Status::Cancelled, $request->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'quotation_supplier_availability.cancelled', 'auditable_id' => $request->id]);
    }

    public function test_a_manual_confirmation_is_read_only(): void
    {
        [$quotation] = $this->shortageQuotation('3');
        app(IssueQuotationAction::class)->execute($this->owner, $quotation->fresh(), true);
        $manual = QuotationSupplierAvailabilityRequest::query()->sole();

        $this->actingAs($this->owner)
            ->from(route('procurement.supplier-requests.index'))
            ->patch(route('quotations.sourcing.record', [$quotation->id, $manual->id]), ['outcome' => 'unavailable'])
            ->assertSessionHasErrors();

        $this->assertSame(Status::ConfirmedManually, $manual->fresh()->status);
        $this->assertSame('3.0000', $manual->fresh()->confirmed_quantity);
    }

    public function test_filters_search_supplier_status_and_date_range(): void
    {
        $customer = $this->createCustomer($this->organization, 'Hotel Atlas');
        [$quotationA, $lineA] = $this->shortageQuotation('4', ['customer_id' => $customer->id]);
        $a = $this->requestSupplier($quotationA, $lineA);

        $other = $this->createProduct($this->organization, 'Projecteur Epson', 'EPS-1', ['default_sale_price' => '10.0000'])->variants->firstOrFail();
        $otherSupplier = $this->supplier($this->organization, 'Visio Import');
        $quotationB = $this->createQuotation($this->owner, $this->organization, $this->store);
        $lineB = $this->addCatalogQuotationLine($this->owner, $quotationB, $other, ['quantity' => '2']);
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00'));
        $b = $this->requestSupplier($quotationB, $lineB, $otherSupplier);

        $this->assertInboxIds(['search' => 'Epson'], [$b->id]);
        $this->assertInboxIds(['search' => 'Atlas'], [$a->id]);
        $this->assertInboxIds(['search' => 'Visio'], [$b->id]);
        $this->assertInboxIds(['search' => 'MIC-INBOX'], [$a->id]);
        $this->assertInboxIds(['supplier_id' => $this->supplier->id], [$a->id]);
        $this->assertInboxIds(['from' => '2026-10-03'], [$b->id]);
        $this->assertInboxIds(['to' => '2026-10-02'], [$a->id]);
        $this->assertInboxIds(['status' => 'unavailable'], []);

        // Supplier filter options only list suppliers used in this store's requests.
        $this->supplier($this->organization, 'Unused Supplier');
        $this->inbox()->assertInertia(fn (Assert $page) => $page->has('suppliers', 2));
    }

    public function test_the_empty_inbox_renders_the_page_with_no_rows(): void
    {
        $this->inbox()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component(self::PAGE)->has('requests.data', 0)->where('filters.status', 'open'));
    }

    public function test_no_cross_organization_leakage(): void
    {
        $this->pendingRequest();

        $otherOwner = User::factory()->create();
        $otherOrganization = $this->createOrganization($otherOwner, 'Other');
        $otherStore = $this->createStore($otherOrganization, $otherOwner);
        $this->activate($otherOwner, $otherOrganization, $otherStore);

        $this->actingAs($otherOwner)->get(route('procurement.supplier-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 0)->has('suppliers', 0));
    }

    public function test_store_visibility_is_respected(): void
    {
        $this->pendingRequest();

        // Same organisation, another store: its own inbox is empty.
        $secondStore = $this->createStore($this->organization, $this->owner, 'Second');
        $this->activate($this->owner, $this->organization, $secondStore);
        $this->inbox()->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));

        // A member of the organisation who is not a member of the Devis store cannot open it there.
        $outsider = User::factory()->create();
        $this->addOrganizationMember($this->organization, $outsider, ['quotations.view', 'procurement.view', 'suppliers.view']);
        $this->addStoreMember($secondStore, $outsider);
        $this->activate($outsider, $this->organization, $secondStore);
        $this->actingAs($outsider)->get(route('procurement.supplier-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));
    }

    public function test_unauthorized_users_cannot_access_the_page_or_the_actions(): void
    {
        $request = $this->pendingRequest();

        foreach ([
            ['quotations.view', 'suppliers.view'],          // no procurement.view
            ['quotations.view', 'procurement.view'],        // no suppliers.view
            ['procurement.view', 'suppliers.view'],         // no quotations.view
        ] as $permissions) {
            $user = $this->member($permissions);
            $this->actingAs($user)->get(route('procurement.supplier-requests.index'))->assertForbidden();
        }

        // Read-only purchasing viewer cannot record or cancel through the canonical routes.
        $viewer = $this->member(['quotations.view', 'procurement.view', 'suppliers.view']);
        $this->actingAs($viewer)
            ->patch(route('quotations.sourcing.record', [$request->quotation_id, $request->id]), ['outcome' => 'available'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('quotations.sourcing.cancel', [$request->quotation_id, $request->id]))
            ->assertForbidden();
        $this->assertSame(Status::Pending, $request->fresh()->status);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function inbox(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->get(route('procurement.supplier-requests.index', $query));
    }

    /** @param list<int> $expected */
    private function assertInboxIds(array $query, array $expected): void
    {
        $this->inbox($query)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('requests.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect($expected)->sort()->values()->all()));
    }

    /** @return array{Quotation, QuotationLine} */
    private function shortageQuotation(string $quantity, array $attributes = []): array
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->store, $attributes);
        $line = $this->addCatalogQuotationLine($this->owner, $quotation, $this->variant, ['quantity' => $quantity]);

        return [$quotation, $line];
    }

    private function pendingRequest(string $quantity = '5'): QuotationSupplierAvailabilityRequest
    {
        [$quotation, $line] = $this->shortageQuotation($quantity);

        return $this->requestSupplier($quotation, $line);
    }

    private function requestSupplier(Quotation $quotation, QuotationLine $line, ?Supplier $supplier = null): QuotationSupplierAvailabilityRequest
    {
        return app(CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation, $line, [
            'supplier_id' => ($supplier ?? $this->supplier)->id,
        ]);
    }

    private function record(QuotationSupplierAvailabilityRequest $request, string $outcome, ?string $confirmed = null): void
    {
        [$quotation] = $this->quotationAndRequest($request);
        app(RecordQuotationSupplierAvailabilityAction::class)->execute($this->owner, $quotation, $request, [
            'outcome' => $outcome,
            'confirmed_quantity' => $confirmed,
        ]);
    }

    /** @return array{Quotation, QuotationSupplierAvailabilityRequest} */
    private function quotationAndRequest(QuotationSupplierAvailabilityRequest $request): array
    {
        return [Quotation::query()->findOrFail($request->quotation_id), $request];
    }

    /** @param list<string> $permissions */
    private function member(array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->organization, $user, $permissions);
        $this->addStoreMember($this->store, $user);
        $this->activate($user, $this->organization, $this->store);

        return $user;
    }

    private function supplier(Organization $organization, string $name): Supplier
    {
        $supplier = new Supplier;
        $supplier->organization_id = $organization->id;
        $supplier->name = $name;
        $supplier->active = true;
        $supplier->save();

        return $supplier;
    }
}

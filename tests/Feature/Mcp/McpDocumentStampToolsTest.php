<?php

namespace Tests\Feature\Mcp;

use App\Models\AuditLog;
use App\Models\DocumentStampApposition;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Mcp\Documents\DocumentStampTools;
use App\Services\Mcp\Finance\FinanceTools;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpExecutionContext;
use Illuminate\Support\Facades\Storage;
use Tests\Support\QuotationTestCase;

/** MCP "Apposer le cachet": the canonical StampInvoiceAction / StampQuotationAction, scope ∩ ERP permission ∩ store. */
class McpDocumentStampToolsTest extends QuotationTestCase
{
    private const SCOPES = ['documents.stamp', 'invoices.view', 'quotations.view'];

    private User $owner;

    private Organization $organization;

    private Store $store;

    private SalesOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->owner, $this->organization, $this->store, $this->order] = $this->documentFixture();
        $this->organization->settings = [...($this->organization->settings ?? []), 'allow_mcp_connections' => true];
        $this->organization->save();
        $this->organization->refresh();
    }

    public function test_an_issued_invoice_is_previewed_stamped_and_its_pdf_then_shows_the_stamp(): void
    {
        $this->configureOrganizationStamp($this->organization);
        $invoice = $this->issuedInvoice();
        $this->connect($this->owner);

        $preview = $this->tool('previewDocumentStamp', ['document_type' => 'invoice', 'document_id' => $invoice->id])['data'];
        $this->assertTrue($preview['valid'], json_encode($preview['issues']));
        $this->assertTrue($preview['company_stamp']['configured']);
        $this->assertFalse($preview['already_stamped']);
        $this->assertSame($invoice->invoice_number, $preview['document']['number']);
        $this->assertSame(0, DocumentStampApposition::query()->count(), 'The preview stamps nothing.');

        $applied = $this->tool('applyDocumentStamp', ['document_type' => 'invoice', 'document_id' => $invoice->id, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'stamp-1']);
        $this->assertTrue($applied['ok'], json_encode($applied));
        $this->assertTrue($applied['data']['company_stamp_applied']);
        $this->assertSame(1, DocumentStampApposition::query()->where('stampable_id', $invoice->id)->count());
        $log = AuditLog::query()->where('event', 'invoice.stamped')->sole();
        $this->assertSame('mcp', $log->actor_channel);
        $this->assertSame($this->owner->id, $log->actor_id);
        // Contents, number and status unchanged.
        $fresh = $invoice->fresh();
        $this->assertSame($invoice->invoice_number, $fresh->invoice_number);
        $this->assertSame($invoice->status, $fresh->status);
        $this->assertSame((string) $invoice->total_incl_tax, (string) $fresh->total_incl_tax);

        $document = $this->tool('getInvoiceDocument', ['invoice_id' => $invoice->id, 'include_company_stamp' => true], FinanceTools::class)['data'];
        $this->assertTrue($document['company_stamp_applied']);
        $this->assertSame([], $document['warnings']);
    }

    public function test_stamping_twice_never_creates_a_second_apposition(): void
    {
        $this->configureOrganizationStamp($this->organization);
        $invoice = $this->issuedInvoice();
        $this->connect($this->owner);
        $arguments = ['document_type' => 'invoice', 'document_id' => $invoice->id];
        $first = $this->tool('previewDocumentStamp', $arguments)['data'];
        $second = $this->tool('previewDocumentStamp', $arguments)['data'];

        $this->tool('applyDocumentStamp', [...$arguments, 'preview_fingerprint' => $first['preview_fingerprint'], 'idempotency_key' => 'stamp-1']);
        $replay = $this->tool('applyDocumentStamp', [...$arguments, 'preview_fingerprint' => $first['preview_fingerprint'], 'idempotency_key' => 'stamp-1']);
        $this->assertTrue($replay['data']['idempotent_replay']);

        $again = $this->tool('applyDocumentStamp', [...$arguments, 'preview_fingerprint' => $second['preview_fingerprint'], 'idempotency_key' => 'stamp-2']);
        $this->assertSame('MCP_DOCUMENT_ALREADY_STAMPED', $again['error']['code']);
        $this->assertSame($invoice->id, $again['error']['details']['document_id']);

        $preview = $this->tool('previewDocumentStamp', $arguments)['data'];
        $this->assertFalse($preview['valid']);
        $this->assertTrue($preview['already_stamped']);
        $this->assertSame('already_stamped', $preview['issues'][0]['code']);
        $this->assertSame(1, DocumentStampApposition::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'invoice.stamped')->count());
    }

    public function test_requesting_the_pdf_with_the_stamp_never_stamps_the_document(): void
    {
        $this->configureOrganizationStamp($this->organization);
        $invoice = $this->issuedInvoice();
        $this->connect($this->owner);

        $document = $this->tool('getInvoiceDocument', ['invoice_id' => $invoice->id, 'include_company_stamp' => true], FinanceTools::class)['data'];

        $this->assertFalse($document['company_stamp_applied']);
        $this->assertSame('stamp_not_apposed', $document['warnings'][0]['code']);
        $this->assertSame(0, DocumentStampApposition::query()->count());
    }

    public function test_a_missing_company_stamp_or_an_ineligible_document_is_refused(): void
    {
        $issued = $this->issuedInvoice();
        $draft = $this->createInvoice($this->owner, $this->secondOrder());
        $this->connect($this->owner);

        $missing = $this->tool('previewDocumentStamp', ['document_type' => 'invoice', 'document_id' => $issued->id])['data'];
        $this->assertFalse($missing['valid']);
        $this->assertFalse($missing['company_stamp']['configured']);
        $this->assertSame('stamp_not_configured', $missing['issues'][0]['code']);
        $this->assertNull($missing['preview_fingerprint']);

        app()->forgetScopedInstances();
        $this->configureOrganizationStamp($this->organization);
        $this->connect($this->owner);
        $ineligible = $this->tool('previewDocumentStamp', ['document_type' => 'invoice', 'document_id' => $draft->id])['data'];
        $this->assertSame('document_not_eligible', $ineligible['issues'][0]['code']);
        $this->assertSame(0, DocumentStampApposition::query()->count());
    }

    public function test_a_stale_preview_is_rejected(): void
    {
        $this->configureOrganizationStamp($this->organization);
        $invoice = $this->issuedInvoice();
        $this->connect($this->owner);
        $preview = $this->tool('previewDocumentStamp', ['document_type' => 'invoice', 'document_id' => $invoice->id])['data'];

        // The company stamp is replaced after the preview.
        $this->configureOrganizationStamp($this->organization, ['display_width_mm' => 30]);

        $stale = $this->tool('applyDocumentStamp', ['document_type' => 'invoice', 'document_id' => $invoice->id, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'stamp-1']);
        $this->assertSame('MCP_PREVIEW_STALE', $stale['error']['code']);
        $this->assertSame(0, DocumentStampApposition::query()->count());
    }

    public function test_scope_permission_store_and_organization_are_all_required(): void
    {
        $this->configureOrganizationStamp($this->organization);
        $invoice = $this->issuedInvoice();

        // No documents.stamp scope.
        $this->assertNotContains('apply_document_stamp', $this->listTools($this->owner, ['invoices.view']));
        $this->connect($this->owner, ['invoices.view']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool('previewDocumentStamp', ['document_type' => 'invoice', 'document_id' => $invoice->id])['error']['code']);

        // Scope but no ERP permission.
        $viewer = $this->member([$this->store], ['invoices.view']);
        $this->assertNotContains('preview_document_stamp', $this->listTools($viewer, self::SCOPES));
        $this->connect($viewer);
        $this->assertSame('MCP_FORBIDDEN', $this->tool('previewDocumentStamp', ['document_type' => 'invoice', 'document_id' => $invoice->id])['error']['code']);

        // Permission, but not a member of the invoice's store.
        $otherStore = $this->createStore($this->organization, $this->owner, 'Autre magasin');
        $outsider = $this->member([$otherStore], ['invoices.view', 'documents.stamp']);
        $this->assertContains('apply_document_stamp', $this->listTools($outsider, self::SCOPES));
        $this->connect($outsider);
        $this->assertSame('MCP_DOCUMENT_NOT_FOUND', $this->tool('previewDocumentStamp', ['document_type' => 'invoice', 'document_id' => $invoice->id])['error']['code']);

        // Another organization.
        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Other');
        $foreign->settings = ['allow_mcp_connections' => true];
        $foreign->save();
        $this->connect($foreignOwner, self::SCOPES, $foreign->refresh());
        $this->assertSame('MCP_DOCUMENT_NOT_FOUND', $this->tool('previewDocumentStamp', ['document_type' => 'invoice', 'document_id' => $invoice->id])['error']['code']);

        $this->assertSame(0, DocumentStampApposition::query()->count());
    }

    public function test_an_issued_quotation_can_be_stamped_but_not_a_draft(): void
    {
        $this->configureOrganizationStamp($this->organization);
        $this->activate($this->owner, $this->organization, $this->store);
        $draft = $this->createQuotation($this->owner, $this->organization, $this->store);
        $this->addNonStockQuotationLine($this->owner, $draft, ['name' => 'Installation', 'unit_price' => '800', 'quantity' => '1']);
        $issued = $this->createQuotation($this->owner, $this->organization, $this->store);
        $this->addNonStockQuotationLine($this->owner, $issued, ['name' => 'Formation', 'unit_price' => '500', 'quantity' => '1']);
        $issued = $this->issueQuotation($this->owner, $issued);
        $this->connect($this->owner);

        $this->assertSame('document_not_eligible', $this->tool('previewDocumentStamp', ['document_type' => 'quotation', 'document_id' => $draft->id])['data']['issues'][0]['code']);

        $preview = $this->tool('previewDocumentStamp', ['document_type' => 'quotation', 'document_id' => $issued->id])['data'];
        $this->assertTrue($preview['valid'], json_encode($preview['issues']));
        $applied = $this->tool('applyDocumentStamp', ['document_type' => 'quotation', 'document_id' => $issued->id, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'q-stamp']);
        $this->assertTrue($applied['ok'], json_encode($applied));
        $this->assertSame('quotation', $applied['data']['document']['type']);
        $this->assertSame('mcp', AuditLog::query()->where('event', 'quotation.stamped')->sole()->actor_channel);
        $this->assertSame(1, $issued->fresh()->stampApposition()->count());

        $this->assertSame('MCP_VALIDATION_FAILED', $this->tool('previewDocumentStamp', ['document_type' => 'delivery_note', 'document_id' => 1])['error']['code']);
    }

    /* ================================================================ Helpers */

    private function issuedInvoice(): Invoice
    {
        app()->forgetScopedInstances();
        $this->activate($this->owner, $this->organization, $this->store);

        return $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $this->order))->fresh();
    }

    private function secondOrder(): SalesOrder
    {
        app()->forgetScopedInstances();
        $this->activate($this->owner, $this->organization, $this->store);
        $order = $this->createDraftOrder($this->owner, $this->organization, $this->store, $this->order->customer, ['sale_date' => '2026-05-29']);
        $this->addCustomLine($this->owner, $order, ['description' => 'Conseil', 'unit_price_excl_tax' => '50.0000']);

        return app(\App\Actions\Sales\ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
    }

    /** @param list<string> $scopes */
    private function connect(User $user, array $scopes = self::SCOPES, ?Organization $organization = null): void
    {
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($user, $organization ?? $this->organization, 'Agent', $scopes, null);
        app(McpExecutionContext::class)->bind($issued['connection']->load(['organization', 'user', 'scopeRecords']));
    }

    /** @return array<string, mixed> */
    private function tool(string $method, array $arguments = [], string $class = DocumentStampTools::class): array
    {
        return app($class)->{$method}(...$arguments)->structuredContent;
    }

    /** @param list<Store> $stores @param list<string> $permissions */
    private function member(array $stores, array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->organization, $user, $permissions);
        foreach ($stores as $store) {
            $this->addStoreMember($store, $user);
        }

        return $user;
    }

    /** @param list<string> $scopes @return list<string> */
    private function listTools(User $user, array $scopes): array
    {
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($user, $this->organization, 'Agent', $scopes, null);
        $headers = ['Authorization' => 'Bearer '.$issued['secret'], 'Accept' => 'application/json, text/event-stream'];
        $session = $this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]])->headers->get('Mcp-Session-Id');
        $headers += ['Mcp-Session-Id' => $session, 'Mcp-Protocol-Version' => '2025-11-25'];
        $this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $names = array_column($this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass])->json('result.tools') ?? [], 'name');
        $this->flushHeaders();

        return $names;
    }
}

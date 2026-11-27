<?php

namespace Tests\Feature\Finance;

use App\Actions\SupplierInvoices\AttachSupplierInvoiceFileAction;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceAttachment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SupplierInvoices\SupplierInvoicePresenter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\SupplierInvoiceTestCase;

/** Phase 2 Sprint 1 (PR6): supplier invoice attachments (security, limits, authorization, streaming). */
class SupplierInvoiceAttachmentTest extends SupplierInvoiceTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['supplier_invoices.enabled' => true, 'supplier_invoices.attachments.disk' => 'local', 'inertia.testing.ensure_pages_exist' => false]);
        Storage::fake('local');
        [$this->owner, $this->org, $this->store, $this->supplier] = $this->invoiceFixture();
    }

    private function pdf(string $name = 'facture.pdf', string $body = 'one'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% {$body}\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    private function png(string $name = 'scan.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    private function draft(array $overrides = []): SupplierInvoice
    {
        return $this->makeInvoice($this->org, $this->store, $this->supplier, $overrides);
    }

    private function upload(SupplierInvoice $invoice, UploadedFile $file, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post(route('finance.supplier-invoices.attachments.store', $invoice), ['file' => $file], ['Accept' => 'application/json']);
    }

    private function member(array $permissions, ?Store $store = null): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->org, $user, $permissions, roleName: 'Role'.Str::random(4));
        $this->addStoreMember($store ?? $this->store, $user);
        $this->activate($user, $this->org, $store ?? $this->store);

        return $user;
    }

    /** @return list<string> */
    private function storedFiles(): array
    {
        return Storage::disk('local')->allFiles();
    }

    public function test_a_pdf_and_an_image_are_stored_privately_with_hash_audit_and_a_random_key(): void
    {
        $invoice = $this->draft();

        $this->upload($invoice, $this->pdf())->assertRedirect(route('finance.supplier-invoices.show', $invoice));
        $this->upload($invoice, $this->png())->assertRedirect();

        $rows = SupplierInvoiceAttachment::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('application/pdf', $rows[0]->mime);
        $this->assertSame('image/png', $rows[1]->mime);
        $this->assertSame(64, strlen($rows[0]->sha256));
        $this->assertSame($this->owner->id, $rows[0]->uploaded_by_user_id);
        $this->assertMatchesRegularExpression('#^supplier-invoices/'.$this->org->id.'/'.$invoice->id.'/[0-9a-z]{26}\.pdf$#', $rows[0]->storage_key);
        $this->assertCount(2, $this->storedFiles());
        Storage::disk('local')->assertExists($rows[0]->storage_key);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supplier_invoice.attachment_added', 'auditable_id' => $invoice->id, 'store_id' => $this->store->id]);
    }

    public function test_content_is_sniffed_so_spoofed_extensions_and_client_mime_are_rejected(): void
    {
        $invoice = $this->draft();
        $spoofs = [
            UploadedFile::fake()->createWithContent('shell.pdf', '<?php echo shell_exec($_GET["c"]); ?>'),
            UploadedFile::fake()->createWithContent('virus.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00"),
            UploadedFile::fake()->createWithContent('page.png', '<html><script>alert(1)</script></html>'),
            UploadedFile::fake()->createWithContent('note.txt', 'plain text'),
            UploadedFile::fake()->create('claimed.pdf', 10, 'application/pdf'),
        ];
        foreach ($spoofs as $file) {
            $this->upload($invoice, $file)->assertStatus(422)->assertJsonValidationErrors('file');
        }

        $this->assertDatabaseCount('supplier_invoice_attachments', 0);
        $this->assertSame([], $this->storedFiles());
    }

    public function test_oversize_and_count_limits(): void
    {
        $invoice = $this->draft();
        config(['supplier_invoices.attachments.max_kb' => 1]);
        $this->upload($invoice, $this->pdf('big.pdf', str_repeat('x', 3000)))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('supplier_invoice_attachments', 0);

        config(['supplier_invoices.attachments.max_kb' => 10240, 'supplier_invoices.attachments.max_files' => 2]);
        $this->upload($invoice, $this->pdf('a.pdf', 'a'))->assertRedirect();
        $this->upload($invoice, $this->pdf('b.pdf', 'b'))->assertRedirect();
        $this->upload($invoice, $this->pdf('c.pdf', 'c'))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('supplier_invoice_attachments', 2);
        $this->assertCount(2, $this->storedFiles());
    }

    public function test_the_same_file_twice_on_one_invoice_is_a_422_and_on_another_invoice_a_soft_warning(): void
    {
        $first = $this->draft();
        $second = $this->draft();
        $this->upload($first, $this->pdf('x.pdf', 'same'))->assertRedirect();

        $this->upload($first, $this->pdf('renamed.pdf', 'same'))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('supplier_invoice_attachments', 1);
        $this->assertCount(1, $this->storedFiles());

        $this->upload($second, $this->pdf('x.pdf', 'same'))->assertRedirect()
            ->assertSessionHas('attachment_warnings', fn ($w) => $w[0]['code'] === 'attachment_duplicate_file' && str_contains($w[0]['message'], $first->internal_number));
        $this->assertDatabaseCount('supplier_invoice_attachments', 2);

        // A void invoice does not trigger the warning.
        DB::table('supplier_invoices')->where('id', $first->id)->update(['status' => 'void', 'dedupe_key' => null]);
        $third = $this->draft();
        $this->upload($third, $this->pdf('x.pdf', 'same'))->assertRedirect()->assertSessionHas('attachment_warnings', fn ($w) => str_contains($w[0]['message'], $second->internal_number) && ! str_contains($w[0]['message'], $first->internal_number));
    }

    public function test_the_soft_warning_never_names_invoices_of_stores_the_user_cannot_see(): void
    {
        $other = $this->createStore($this->org, $this->owner, 'Autre');
        $hidden = $this->makeInvoice($this->org, $other, $this->supplier);
        $this->upload($hidden, $this->pdf('h.pdf', 'hidden'))->assertRedirect();

        $user = $this->member(['supplier_invoices.view', 'supplier_invoices.manage']);
        $mine = $this->draft();
        $this->upload($mine, $this->pdf('h.pdf', 'hidden'), $user)->assertRedirect()->assertSessionHas('attachment_warnings', []);
    }

    public function test_attach_while_approved_is_allowed_and_void_is_refused(): void
    {
        $approved = $this->forceApproved($this->draft());
        $this->upload($approved, $this->pdf('a.pdf', 'appr'))->assertRedirect();
        $this->assertDatabaseCount('supplier_invoice_attachments', 1);

        $void = $this->draft();
        DB::table('supplier_invoices')->where('id', $void->id)->update(['status' => 'void', 'dedupe_key' => null]);
        $this->upload($void, $this->pdf('v.pdf', 'void'))->assertStatus(422);
        $this->assertDatabaseCount('supplier_invoice_attachments', 1);
        $this->assertCount(1, $this->storedFiles());
    }

    public function test_removal_is_draft_only_and_deletes_row_and_file(): void
    {
        $draft = $this->draft();
        $this->upload($draft, $this->pdf('d.pdf', 'draft'))->assertRedirect();
        $attachment = SupplierInvoiceAttachment::query()->sole();
        $key = $attachment->storage_key;

        $this->actingAs($this->owner)->delete(route('finance.supplier-invoices.attachments.destroy', [$draft, $attachment]), [], ['Accept' => 'application/json'])->assertRedirect();
        $this->assertDatabaseCount('supplier_invoice_attachments', 0);
        Storage::disk('local')->assertMissing($key);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supplier_invoice.attachment_removed', 'auditable_id' => $draft->id]);

        $approved = $this->draft();
        $this->upload($approved, $this->pdf('e.pdf', 'appr'))->assertRedirect();
        $this->forceApproved($approved);
        $kept = SupplierInvoiceAttachment::query()->sole();
        $this->actingAs($this->owner)->delete(route('finance.supplier-invoices.attachments.destroy', [$approved, $kept]), [], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertDatabaseCount('supplier_invoice_attachments', 1);
        Storage::disk('local')->assertExists($kept->storage_key);
    }

    public function test_removal_requires_the_attachment_to_belong_to_the_invoice(): void
    {
        $a = $this->draft();
        $b = $this->draft();
        $this->upload($a, $this->pdf('a.pdf', 'aa'))->assertRedirect();
        $attachment = SupplierInvoiceAttachment::query()->sole();

        $this->actingAs($this->owner)->delete(route('finance.supplier-invoices.attachments.destroy', [$b, $attachment]), [], ['Accept' => 'application/json'])->assertNotFound();
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.attachments.show', [$b, $attachment]))->assertNotFound();
        $this->assertDatabaseCount('supplier_invoice_attachments', 1);
    }

    public function test_authorization_matrix_permission_store_cross_organization_kill_switch_and_test_mode(): void
    {
        $invoice = $this->draft();
        $this->upload($invoice, $this->pdf('a.pdf', 'seed'))->assertRedirect();
        $attachment = SupplierInvoiceAttachment::query()->sole();

        // No manage permission: view is not enough to add or remove.
        $viewer = $this->member(['supplier_invoices.view']);
        $this->upload($invoice, $this->pdf('n.pdf', 'n'), $viewer)->assertForbidden();
        $this->actingAs($viewer)->delete(route('finance.supplier-invoices.attachments.destroy', [$invoice, $attachment]), [], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($viewer)->get(route('finance.supplier-invoices.attachments.show', [$invoice, $attachment]))->assertOk();

        // No permission at all.
        $none = $this->member(['suppliers.view']);
        $this->upload($invoice, $this->pdf('n.pdf', 'n'), $none)->assertForbidden();
        $this->actingAs($none)->get(route('finance.supplier-invoices.attachments.show', [$invoice, $attachment]))->assertForbidden();

        // Permission but no membership of the invoice's store (owner included: no bypass).
        $elsewhere = $this->createStore($this->org, $this->owner, 'Ailleurs');
        $outsider = $this->member(['supplier_invoices.view', 'supplier_invoices.manage'], $elsewhere);
        $this->upload($invoice, $this->pdf('n.pdf', 'n'), $outsider)->assertForbidden();
        $this->actingAs($outsider)->get(route('finance.supplier-invoices.attachments.show', [$invoice, $attachment]))->assertForbidden();
        $foreignStoreInvoice = $this->makeInvoice($this->org, $elsewhere, $this->supplier);
        DB::table('store_memberships')->where('store_id', $elsewhere->id)->where('user_id', $this->owner->id)->delete();
        $this->upload($foreignStoreInvoice, $this->pdf('o.pdf', 'o'))->assertForbidden();

        // Another organization: the invoice cannot even be resolved.
        $foreignOwner = User::factory()->create();
        $foreignOrg = $this->createOrganization($foreignOwner, 'Foreign');
        $this->createStore($foreignOrg, $foreignOwner);
        $this->activate($foreignOwner, $foreignOrg);
        $this->upload($invoice, $this->pdf('f.pdf', 'f'), $foreignOwner)->assertNotFound();
        $this->actingAs($foreignOwner)->get(route('finance.supplier-invoices.attachments.show', [$invoice, $attachment]))->assertNotFound();

        // Kill switch.
        config(['supplier_invoices.enabled' => false]);
        $this->upload($invoice, $this->pdf('k.pdf', 'k'))->assertNotFound();
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.attachments.show', [$invoice, $attachment]))->assertNotFound();
        $this->actingAs($this->owner)->delete(route('finance.supplier-invoices.attachments.destroy', [$invoice, $attachment]), [], ['Accept' => 'application/json'])->assertNotFound();
        config(['supplier_invoices.enabled' => true]);

        $this->assertDatabaseCount('supplier_invoice_attachments', 1);
    }

    public function test_test_mode_denies_the_attachment_routes(): void
    {
        $invoice = $this->draft();
        $this->upload($invoice, $this->pdf('a.pdf', 'seed'))->assertRedirect();
        $attachment = SupplierInvoiceAttachment::query()->sole();
        $this->actingAs($this->owner)->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();

        $this->post(route('finance.supplier-invoices.attachments.store', $invoice), ['file' => $this->pdf('t.pdf', 't')], ['Accept' => 'application/json'])
            ->assertForbidden()->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');
        $this->delete(route('finance.supplier-invoices.attachments.destroy', [$invoice, $attachment]), [], ['Accept' => 'application/json'])
            ->assertForbidden()->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');
        $this->get(route('finance.supplier-invoices.attachments.show', [$invoice, $attachment]))->assertRedirect('/test-mode');
        $this->post('/test-mode/leave')->assertRedirect();
        $this->get('/test-mode')->assertOk();
        $this->assertDatabaseCount('supplier_invoice_attachments', 1);
    }

    public function test_the_stream_is_authorized_inline_with_safe_headers_and_no_public_url(): void
    {
        $invoice = $this->draft();
        $this->upload($invoice, $this->pdf('Facture mars.pdf', 'stream'))->assertRedirect();
        $attachment = SupplierInvoiceAttachment::query()->sole();

        $response = $this->actingAs($this->owner)->get(route('finance.supplier-invoices.attachments.show', [$invoice, $attachment]));
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($attachment->storage_key, route('finance.supplier-invoices.attachments.show', [$invoice, $attachment]));
        $this->assertStringNotContainsString('/storage/', route('finance.supplier-invoices.attachments.show', [$invoice, $attachment]));

        Storage::disk('local')->delete($attachment->storage_key);
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.attachments.show', [$invoice, $attachment]))->assertNotFound();
    }

    public function test_client_file_name_is_display_only_and_path_traversal_is_neutralised(): void
    {
        $invoice = $this->draft();
        $this->upload($invoice, $this->pdf('../../../etc/passwd.pdf', 'trav'))->assertRedirect();
        $this->upload($invoice, $this->pdf('..\\..\\windows\\evil<script>.pdf', 'trav2'))->assertRedirect();

        foreach (SupplierInvoiceAttachment::query()->get() as $attachment) {
            $this->assertStringNotContainsString('/', $attachment->original_name);
            $this->assertStringNotContainsString('\\', $attachment->original_name);
            $this->assertStringNotContainsString('<', $attachment->original_name);
            $this->assertStringNotContainsString('..', $attachment->original_name);
            $this->assertStringStartsWith("supplier-invoices/{$this->org->id}/{$invoice->id}/", $attachment->storage_key);
            $this->assertStringNotContainsString('passwd', $attachment->storage_key);
        }
        $this->assertCount(2, $this->storedFiles());
    }

    public function test_a_failed_write_removes_the_stored_file_and_leaves_no_row(): void
    {
        $invoice = $this->draft();
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('record')->willThrowException(new RuntimeException('audit down'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->owner->refresh();

        try {
            app(AttachSupplierInvoiceFileAction::class)->execute($this->owner, $this->org, $invoice, $this->pdf('f.pdf', 'fail'));
            $this->fail('Expected failure');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('supplier_invoice_attachments', 0);
        $this->assertSame([], $this->storedFiles());
    }

    public function test_presenter_url_and_can_attach(): void
    {
        $invoice = $this->draft();
        $this->upload($invoice, $this->pdf('a.pdf', 'p'))->assertRedirect();
        $presenter = app(SupplierInvoicePresenter::class);
        $loaded = SupplierInvoice::query()->with(['organization', 'supplier', 'store', 'purchaseOrder', 'createdBy', 'approvedBy', 'voidedBy', 'attachments', 'lines.productVariant.product', 'installments.preferredFinancialAccount'])->findOrFail($invoice->id);

        $attachment = $presenter->detail($loaded)['attachments'][0];
        $this->assertSame(route('finance.supplier-invoices.attachments.show', [$invoice, $loaded->attachments->first()]), $attachment['url']);

        $this->assertTrue($presenter->canAttach($this->owner, $loaded));
        $this->assertFalse($presenter->canAttach($this->member(['supplier_invoices.view']), $loaded));
        DB::table('supplier_invoices')->where('id', $invoice->id)->update(['status' => 'void', 'dedupe_key' => null]);
        $this->assertFalse($presenter->canAttach($this->owner, $loaded->fresh()));
    }
}

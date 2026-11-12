<?php

namespace Tests\Feature\Sandbox;

use App\Models\DocumentEmailDelivery;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Services\OrganizationOutboundMailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\QuotationTestCase;

/** Parity phase: Test quotations reuse the Production lifecycle and documents, with simulated outbound sends. */
class SandboxQuotationFlowTest extends QuotationTestCase
{
    public function test_test_quotation_documents_lifecycle_and_simulated_email_and_production_unchanged(): void
    {
        [$owner, $org, $store, $variant] = $this->quotationCatalogFixture();
        $warehouse = $this->createWarehouse($org, 'Main', 'MAIN');
        $this->openStock($owner, $org, $warehouse, $variant, '10.0000');
        $this->actingAs($owner);
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $this->get('/test-mode')->assertOk();

        $quote = $this->createQuotation($owner, $org, $store);
        $this->addCatalogQuotationLine($owner, $quote, $variant);

        // Draft: print / view PDF / download PDF work and are visibly Test documents.
        $this->get("/quotations/{$quote->id}/print")->assertOk()
            ->assertSee('DOCUMENT DE TEST')->assertSee('SANS VALEUR COMMERCIALE / LÉGALE')->assertSee($quote->quotation_number);
        $view = $this->get("/quotations/{$quote->id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $view->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $view->headers->get('Content-Disposition'));
        $download = $this->get("/quotations/{$quote->id}/download")->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));

        // Issue: Test reference from the Test sequence; Production numbering untouched.
        $this->post("/quotations/{$quote->id}/issue")->assertRedirect()->assertSessionHasNoErrors();
        $quote = Quotation::findOrFail($quote->id);
        $this->assertSame('issued', $quote->status->value);
        $this->assertSame('TEST-DEV-000001', $quote->quotation_number);
        $this->assertSame(0, DB::table('quotation_sequences')->count());
        $this->get("/quotations/{$quote->id}/print")->assertOk()->assertSee('TEST-DEV-000001')->assertSee('DOCUMENT DE TEST');
        $this->assertStringContainsString('TEST-DEV-000001', (string) $this->get("/quotations/{$quote->id}/download")->assertOk()->headers->get('Content-Disposition'));
        $this->get("/quotations/{$quote->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('isTest', true)->where('mailConfigured', true)
            ->where('sharing.pdfUrl', route('quotations.pdf', $quote))); // in-session link, no signed public URL

        // E-mail: same validation as Production, never reaches the real mail service.
        $this->mock(OrganizationOutboundMailService::class, fn ($mail) => $mail->shouldNotReceive('send'));
        Mail::fake();
        $this->post("/quotations/{$quote->id}/email", ['email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->post("/quotations/{$quote->id}/email", ['email' => 'client@example.test'])->assertRedirect()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'EMAIL TEST — NON ENVOYÉ'));
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame(0, DocumentEmailDelivery::query()->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'quotation.email_simulated')->count());

        // Stamp: the action is reachable (no company stamp configured here, so Production's own validation answers).
        $this->post("/quotations/{$quote->id}/stamp")->assertSessionHasErrors('stamp');

        // Supplier attestation shortcut (procurement records) stays refused in Test Mode.
        $draft = $this->createQuotation($owner, $org, $store);
        $this->addCatalogQuotationLine($owner, $draft, $variant);
        $this->postJson("/quotations/{$draft->id}/issue", ['confirm_supplier_availability' => true])->assertForbidden();
        // Like Production, a draft cannot be converted: it must be issued and accepted first.
        $this->post("/quotations/{$draft->id}/conversion", ['warehouse_id' => $warehouse->id])->assertForbidden();

        // Accept, then convert to a Test draft order.
        $this->post("/quotations/{$quote->id}/accept")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('accepted', Quotation::findOrFail($quote->id)->status->value);
        $this->post("/quotations/{$quote->id}/conversion", ['warehouse_id' => $warehouse->id])->assertRedirect();
        $quote = Quotation::findOrFail($quote->id);
        $this->assertSame('converted', $quote->status->value);
        $order = SalesOrder::query()->findOrFail($quote->converted_sales_order_id);
        $this->assertNotNull($order->sandbox_id);
        $this->assertStringStartsWith('TEST-', $order->order_number);

        // Still blocked in Test Mode.
        foreach (["/quotations/{$quote->id}/revise", "/quotations/{$quote->id}/duplicate", "/quotations/{$quote->id}/lines/1/supplier-requests",
            '/sales/orders/1/fulfill', '/sales/orders/1/returns'] as $blocked) {
            $this->postJson($blocked, [])->assertForbidden()->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');
        }

        // Production is unchanged: official number, real mail dispatch, no Test marking.
        $this->post('/test-mode/leave')->assertRedirect();
        $this->get('/test-mode')->assertOk();
        $production = $this->createQuotation($owner, $org, $store);
        $this->addCatalogQuotationLine($owner, $production, $variant);
        $production = $this->issueQuotation($owner, $production);
        $this->assertMatchesRegularExpression('/^DEV-1\/\d{4}$/', $production->quotation_number);
        $this->assertSame(1, DB::table('quotation_sequences')->count());
        $this->get("/quotations/{$production->id}/print")->assertOk()
            ->assertDontSee('DOCUMENT DE TEST')->assertDontSee('SANS VALEUR COMMERCIALE')->assertDontSee('(TEST)');
        $this->get("/quotations/{$quote->id}/print")->assertNotFound(); // Test quotation invisible from Production
        $this->mock(OrganizationOutboundMailService::class, fn ($mail) => $mail->shouldReceive('send')->once());
        $this->post("/quotations/{$production->id}/email", ['email' => 'client@example.test'])->assertRedirect()
            ->assertSessionHas('success', 'Devis envoyé par e-mail.');
        $this->assertSame(1, DocumentEmailDelivery::query()->where('status', DocumentEmailDelivery::STATUS_SENT)->count());
    }
}

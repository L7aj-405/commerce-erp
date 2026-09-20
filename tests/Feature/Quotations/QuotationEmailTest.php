<?php

namespace Tests\Feature\Quotations;

use App\Mail\QuotationDocumentMail;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

class QuotationEmailTest extends QuotationTestCase
{
    /** @return array{User, Organization, Store, ProductVariant} */
    private function base(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Micro', 'MIC-1', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);

        return [$owner, $organization, $store, $variant];
    }

    public function test_issued_quotation_is_emailed_with_the_real_pdf_using_organization_sender_identity(): void
    {
        Mail::fake();
        [$owner, $organization, $store, $variant] = $this->base();
        $this->configureOrganizationMail($organization, ['sender_name' => 'Ma Société', 'sender_email' => 'devis@example.test']);
        $customer = $this->createCustomer($organization, 'Client Devis', [
            'company_name' => 'DEVIS SARL', 'email' => 'client@example.test', 'tax_identifier' => 'ICE-D', 'billing_address' => 'Rue A',
        ]);
        $quotation = $this->createQuotation($owner, $organization, $store, ['customer_id' => $customer->id]);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);

        $this->actingAs($owner)->post(route('quotations.email', $issued), ['email' => 'client@example.test'])
            ->assertRedirect();

        $expectedFilename = 'Devis-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $issued->quotation_number).'.pdf';
        Mail::assertSent(QuotationDocumentMail::class, fn (QuotationDocumentMail $mail) => $mail->hasTo('client@example.test')
            && ($mail->from[0]['address'] ?? null) === 'devis@example.test'
            && $mail->attachments()[0]->as === $expectedFilename
            && $mail->attachments()[0]->mime === 'application/pdf');
    }

    public function test_customer_email_is_prefilled_for_sharing(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        $this->configureOrganizationMail($organization);
        $customer = $this->createCustomer($organization, 'Client Devis', [
            'company_name' => 'DEVIS SARL', 'email' => 'prefill@example.test', 'tax_identifier' => 'ICE-D', 'billing_address' => 'Rue A',
        ]);
        $quotation = $this->createQuotation($owner, $organization, $store, ['customer_id' => $customer->id]);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);

        $this->actingAs($owner)->get(route('quotations.show', $issued))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sharing.email', 'prefill@example.test')
                ->where('mailConfigured', true));
    }

    public function test_invalid_recipient_is_rejected_for_quotation_email(): void
    {
        Mail::fake();
        [$owner, $organization, $store, $variant] = $this->base();
        $this->configureOrganizationMail($organization);
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);

        $this->actingAs($owner)->post(route('quotations.email', $issued), ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_quotation_email_is_rejected_when_organization_mail_is_not_configured(): void
    {
        Mail::fake();
        [$owner, $organization, $store, $variant] = $this->base();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);

        $this->actingAs($owner)->post(route('quotations.email', $issued), ['email' => 'client@example.test'])
            ->assertSessionHasErrors('email')
            ->assertSessionHas('mailNotConfigured', true);

        Mail::assertNothingSent();
    }
}

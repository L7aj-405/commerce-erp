<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class DocumentTemplateRegistry
{
    public function currentInvoiceVersion(): string
    {
        return (string) config('documents.invoice_template_version', 'v1');
    }

    public function currentDeliveryNoteVersion(): string
    {
        return (string) config('documents.delivery_note_template_version', 'v1');
    }

    public function currentQuotationVersion(): string
    {
        return (string) config('documents.quotation_template_version', 'v1');
    }

    public function invoiceView(string $version): string
    {
        return $this->view('invoice', $version);
    }

    public function quotationView(string $version): string
    {
        return $this->view('quotation', $version);
    }

    public function deliveryNoteView(string $version): string
    {
        return $this->view('delivery-note', $version);
    }

    public function transferRequestView(string $version): string
    {
        return $this->view('transfer-request', $version);
    }

    private function view(string $document, string $version): string
    {
        if ($version !== 'v1') {
            throw ValidationException::withMessages(['template_version' => 'This document template version is not supported.']);
        }

        return "documents.{$document}.{$version}";
    }
}

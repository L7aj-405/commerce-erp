<?php

namespace App\Services\Mcp\Finance;

use RuntimeException;

/** The Sales Order already has its issued invoice: returned instead of creating a duplicate. */
final class McpInvoiceAlreadyIssued extends RuntimeException
{
    public function __construct(public readonly int $invoiceId, public readonly ?string $invoiceNumber)
    {
        parent::__construct('invoice_already_issued');
    }
}

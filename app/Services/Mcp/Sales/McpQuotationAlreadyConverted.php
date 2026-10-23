<?php

namespace App\Services\Mcp\Sales;

use RuntimeException;

/** The quotation already points to a Sales Order; conversion never creates a second one. */
final class McpQuotationAlreadyConverted extends RuntimeException
{
    public function __construct(public readonly int $salesOrderId, public readonly ?string $orderNumber)
    {
        parent::__construct('quotation_already_converted');
    }
}

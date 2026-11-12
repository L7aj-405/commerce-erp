<?php

namespace App\Services\Sandbox;

final class SandboxSalesRoutes
{
    public const ALLOWED = [
        'pos.index', 'pos.products.index', 'pos.customers.index',
        'pos.drafts.store', 'pos.drafts.update', 'pos.drafts.lines.store',
        'pos.drafts.lines.update', 'pos.drafts.lines.destroy', 'pos.drafts.hold', 'pos.drafts.resume',
        'quotations.index', 'quotations.create', 'quotations.store', 'quotations.show',
        'quotations.update', 'quotations.search', 'quotations.customer-search',
        'quotations.lines.store', 'quotations.lines.update', 'quotations.lines.destroy', 'quotations.conversion.store',
        'sales.orders.index', 'sales.orders.store', 'sales.orders.show', 'sales.orders.edit',
        'sales.orders.update', 'sales.orders.line-search', 'sales.orders.customer-search',
        'sales.orders.lines.store', 'sales.orders.lines.update', 'sales.orders.lines.destroy',
        'context.store',
    ];
}

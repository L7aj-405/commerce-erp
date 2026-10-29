import type { TranslationKey } from '@/i18n/translations';

/** Translation keys for MCP scope keys; unknown scopes fall back to the server label. */
export const mcpScopeTranslationKeys: Record<string, TranslationKey> = {
    'products.view': 'mcp.scope.productsView', 'products.create': 'mcp.scope.productsCreate',
    'products.update': 'mcp.scope.productsUpdate', 'products.archive': 'mcp.scope.productsArchive',
    'products.import': 'mcp.scope.productsImport',
    'supplier_catalog.view': 'mcp.scope.supplierCatalogView', 'supplier_catalog.manage': 'mcp.scope.supplierCatalogManage',
    'product_cost.view': 'mcp.scope.costView', 'product_cost.manage': 'mcp.scope.costManage',
    'inventory.view': 'mcp.scope.inventoryView', 'brands.manage': 'mcp.scope.brandsManage',
    'categories.manage': 'mcp.scope.categoriesManage', 'units.manage': 'mcp.scope.unitsManage',
    'tax_rates.view': 'mcp.scope.taxesView', 'customers.view': 'mcp.scope.customersView',
    'customers.create': 'mcp.scope.customersCreate', 'customers.update': 'mcp.scope.customersUpdate',
    'contacts.view': 'mcp.scope.contactsView', 'contacts.create': 'mcp.scope.contactsCreate',
    'contacts.update': 'mcp.scope.contactsUpdate', 'contacts.archive': 'mcp.scope.contactsArchive',
    'quotations.view': 'mcp.scope.quotationsView', 'quotations.create': 'mcp.scope.quotationsCreate',
    'quotations.update': 'mcp.scope.quotationsUpdate', 'quotations.issue': 'mcp.scope.quotationsIssue',
    'quotations.accept': 'mcp.scope.quotationsAccept', 'quotations.convert': 'mcp.scope.quotationsConvert',
    'quotations.email': 'mcp.scope.quotationsEmail',
    'sales_orders.view': 'mcp.scope.salesOrdersView',
    'sales_orders.create': 'mcp.scope.salesOrdersCreate', 'sales_orders.update': 'mcp.scope.salesOrdersUpdate',
    'sales_orders.confirm': 'mcp.scope.salesOrdersConfirm',
    'follow_ups.view': 'mcp.scope.followUpsView', 'follow_ups.create': 'mcp.scope.followUpsCreate',
    'follow_ups.update': 'mcp.scope.followUpsUpdate', 'follow_ups.manage_all': 'mcp.scope.followUpsManageAll',
    'attention.view': 'mcp.scope.attentionView', 'attention.update': 'mcp.scope.attentionUpdate',
    'timeline.view': 'mcp.scope.timelineView',
};

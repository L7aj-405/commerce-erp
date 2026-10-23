import type { TranslationKey } from '@/i18n/translations';

/** Translation keys for MCP scope keys; unknown scopes fall back to the server label. */
export const mcpScopeTranslationKeys: Record<string, TranslationKey> = {
    'products.view': 'mcp.scope.productsView', 'products.create': 'mcp.scope.productsCreate',
    'products.update': 'mcp.scope.productsUpdate', 'products.archive': 'mcp.scope.productsArchive',
    'product_cost.view': 'mcp.scope.costView', 'product_cost.manage': 'mcp.scope.costManage',
    'inventory.view': 'mcp.scope.inventoryView', 'brands.manage': 'mcp.scope.brandsManage',
    'categories.manage': 'mcp.scope.categoriesManage', 'units.manage': 'mcp.scope.unitsManage',
    'tax_rates.view': 'mcp.scope.taxesView', 'customers.view': 'mcp.scope.customersView',
    'quotations.view': 'mcp.scope.quotationsView', 'quotations.create': 'mcp.scope.quotationsCreate',
    'quotations.update': 'mcp.scope.quotationsUpdate',
};

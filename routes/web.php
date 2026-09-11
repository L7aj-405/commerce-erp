<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Catalog\BrandController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\NonStockItemController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ProductImportController;
use App\Http\Controllers\Catalog\ProductVariantController;
use App\Http\Controllers\Catalog\TaxRateController;
use App\Http\Controllers\Catalog\UnitOfMeasureController;
use App\Http\Controllers\Documents\DeliveryNoteController;
use App\Http\Controllers\Documents\DocumentEmailController;
use App\Http\Controllers\Documents\DocumentProfileController;
use App\Http\Controllers\Documents\DocumentRenderingController;
use App\Http\Controllers\Documents\InvoiceController;
use App\Http\Controllers\Documents\InvoiceCorrectionLineController;
use App\Http\Controllers\Integrations\WooCommerceIntegrationController;
use App\Http\Controllers\Inventory\InventoryMovementController;
use App\Http\Controllers\Inventory\InventoryReservationController;
use App\Http\Controllers\Inventory\InventoryStockController;
use App\Http\Controllers\Inventory\StockTransferController;
use App\Http\Controllers\Inventory\TransferRequestController;
use App\Http\Controllers\Inventory\WarehouseController;
use App\Http\Controllers\Inventory\WarehouseReplenishmentController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationMembershipController;
use App\Http\Controllers\Payments\FinancialAccountController;
use App\Http\Controllers\Payments\PaymentController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\Procurement\ProcurementController;
use App\Http\Controllers\Procurement\SupplierController;
use App\Http\Controllers\Quotations\QuotationController;
use App\Http\Controllers\Quotations\QuotationConversionController;
use App\Http\Controllers\Quotations\QuotationEmailController;
use App\Http\Controllers\Quotations\QuotationLineController;
use App\Http\Controllers\Quotations\QuotationRenderingController;
use App\Http\Controllers\Quotations\QuotationSettingsController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Sales\CustomerController;
use App\Http\Controllers\Sales\SalesOrderController;
use App\Http\Controllers\Sales\SalesOrderLifecycleController;
use App\Http\Controllers\Sales\SalesOrderLineController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\StoreMembershipController;
use App\Http\Controllers\TenantContextController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('platform.index');
    }

    return Inertia::render('Home');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('login.store');

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('register.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/platform', PlatformController::class)->name('platform.index');
    Route::get('/document-profile', [DocumentProfileController::class, 'edit'])->name('document-profile.edit');
    Route::put('/document-profile', [DocumentProfileController::class, 'update'])->name('document-profile.update');
    Route::get('/quotation-settings', [QuotationSettingsController::class, 'edit'])->name('quotation-settings.edit');
    Route::put('/quotation-settings', [QuotationSettingsController::class, 'update'])->name('quotation-settings.update');

    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [PosController::class, 'index'])->name('index');
        Route::get('/products', [PosController::class, 'products'])->name('products.index');
        Route::get('/customers', [PosController::class, 'customers'])->name('customers.index');
        Route::post('/customers', [PosController::class, 'storeCustomer'])->name('customers.store');
        Route::patch('/customers/{customer}', [PosController::class, 'updateCustomer'])->name('customers.update');
        Route::post('/drafts', [PosController::class, 'storeDraft'])->name('drafts.store');
        Route::patch('/drafts/{order}', [PosController::class, 'updateDraft'])->name('drafts.update');
        Route::post('/drafts/{order}/lines', [PosController::class, 'storeDraftLine'])->name('drafts.lines.store');
        Route::patch('/drafts/{order}/lines/{line}', [PosController::class, 'updateDraftLine'])->name('drafts.lines.update');
        Route::delete('/drafts/{order}/lines/{line}', [PosController::class, 'destroyDraftLine'])->name('drafts.lines.destroy');
        Route::post('/drafts/{order}/hold', [PosController::class, 'holdDraft'])->name('drafts.hold');
        Route::post('/drafts/{order}/resume', [PosController::class, 'resumeDraft'])->name('drafts.resume');
        Route::delete('/drafts/{order}', [PosController::class, 'destroyDraft'])->name('drafts.destroy');
        Route::post('/sales', [PosController::class, 'complete'])->name('sales.store');
    });
    Route::post('/context/organizations/{organizationId}', [TenantContextController::class, 'organization'])
        ->whereNumber('organizationId')
        ->name('context.organization');
    Route::post('/context/stores/{storeId}', [TenantContextController::class, 'store'])
        ->whereNumber('storeId')
        ->name('context.store');

    Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
    Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
    Route::patch('/organizations/{organization}/settings', [OrganizationController::class, 'updateSettings'])
        ->name('organizations.settings.update');
    Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy'])->name('organizations.destroy');

    Route::post('/stores', [StoreController::class, 'store'])->name('stores.store');
    Route::get('/stores/{store}', [StoreController::class, 'show'])->name('stores.show');
    Route::patch('/stores/{store}', [StoreController::class, 'update'])->name('stores.update');
    Route::delete('/stores/{store}', [StoreController::class, 'destroy'])->name('stores.destroy');

    Route::post('/organizations/{organization}/memberships', [OrganizationMembershipController::class, 'store'])
        ->name('organization-memberships.store');
    Route::patch('/organization-memberships/{membership}', [OrganizationMembershipController::class, 'update'])
        ->name('organization-memberships.update');
    Route::delete('/organization-memberships/{membership}', [OrganizationMembershipController::class, 'destroy'])
        ->name('organization-memberships.destroy');

    Route::post('/stores/{store}/memberships', [StoreMembershipController::class, 'store'])
        ->name('store-memberships.store');
    Route::delete('/store-memberships/{storeMembership}', [StoreMembershipController::class, 'destroy'])
        ->name('store-memberships.destroy');

    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::patch('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::put('/roles/{role}/permissions', [RoleController::class, 'permissions'])->name('roles.permissions.update');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

    Route::prefix('catalog')->name('catalog.')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/import', [ProductImportController::class, 'create'])->name('product-imports.create');
        Route::post('/products/import', [ProductImportController::class, 'store'])->name('product-imports.store');
        Route::get('/products/import/{productImport}', [ProductImportController::class, 'show'])->name('product-imports.show');
        Route::put('/products/import/{productImport}/preview', [ProductImportController::class, 'preview'])->name('product-imports.preview');
        Route::post('/products/import/{productImport}/confirm', [ProductImportController::class, 'confirm'])->name('product-imports.confirm');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::patch('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::patch('/products/{product}/archive', [ProductController::class, 'archive'])->name('products.archive');

        Route::post('/products/{product}/variants', [ProductVariantController::class, 'store'])->name('variants.store');
        Route::patch('/variants/{variant}', [ProductVariantController::class, 'update'])->name('variants.update');
        Route::patch('/variants/{variant}/archive', [ProductVariantController::class, 'archive'])->name('variants.archive');

        Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
        Route::post('/brands', [BrandController::class, 'store'])->name('brands.store');
        Route::patch('/brands/{brand}', [BrandController::class, 'update'])->name('brands.update');

        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');

        Route::get('/units', [UnitOfMeasureController::class, 'index'])->name('units.index');
        Route::post('/units', [UnitOfMeasureController::class, 'store'])->name('units.store');
        Route::patch('/units/{unit}', [UnitOfMeasureController::class, 'update'])->name('units.update');

        Route::get('/tax-rates', [TaxRateController::class, 'index'])->name('tax-rates.index');
        Route::post('/tax-rates', [TaxRateController::class, 'store'])->name('tax-rates.store');
        Route::patch('/tax-rates/{taxRate}', [TaxRateController::class, 'update'])->name('tax-rates.update');

        // Reusable non-stock / external article library (no inventory footprint).
        Route::get('/non-stock-items', [NonStockItemController::class, 'index'])->name('non-stock-items.index');
        Route::get('/non-stock-items/export', [NonStockItemController::class, 'export'])->name('non-stock-items.export');
        Route::post('/non-stock-items', [NonStockItemController::class, 'store'])->name('non-stock-items.store');
        Route::patch('/non-stock-items/{nonStockItem}', [NonStockItemController::class, 'update'])->name('non-stock-items.update');
    });

    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/stock', [InventoryStockController::class, 'index'])->name('stock.index');
        Route::post('/opening-stock', [InventoryStockController::class, 'opening'])->name('opening.store');
        Route::post('/adjustments', [InventoryStockController::class, 'adjustment'])->name('adjustments.store');

        Route::get('/warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::post('/warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::patch('/warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');

        Route::get('/movements', [InventoryMovementController::class, 'index'])->name('movements.index');

        Route::get('/transfers', [StockTransferController::class, 'index'])->name('transfers.index');
        Route::get('/transfers/create', [StockTransferController::class, 'create'])->name('transfers.create');
        Route::post('/transfers', [StockTransferController::class, 'store'])->name('transfers.store');
        Route::get('/transfers/{transfer}', [StockTransferController::class, 'show'])->name('transfers.show');

        // Internal transfer REQUESTS (logistics lifecycle: requested → preparing
        // → shipped → received). Distinct from the completed movements above.
        Route::get('/transfer-requests', [TransferRequestController::class, 'index'])->name('transfer-requests.index');
        Route::post('/transfer-requests/replenish', [TransferRequestController::class, 'replenish'])->name('transfer-requests.replenish');
        Route::get('/transfer-requests/{transferRequest}', [TransferRequestController::class, 'show'])->name('transfer-requests.show');
        Route::post('/transfer-requests/{transferRequest}/prepare', [TransferRequestController::class, 'prepare'])->name('transfer-requests.prepare');
        Route::patch('/transfer-requests/{transferRequest}/driver', [TransferRequestController::class, 'assignDriver'])->name('transfer-requests.driver');
        Route::post('/transfer-requests/{transferRequest}/ship', [TransferRequestController::class, 'ship'])->name('transfer-requests.ship');
        Route::post('/transfer-requests/{transferRequest}/receive', [TransferRequestController::class, 'receive'])->name('transfer-requests.receive');
        Route::post('/transfer-requests/{transferRequest}/cancel', [TransferRequestController::class, 'cancel'])->name('transfer-requests.cancel');
        Route::get('/transfer-requests/{transferRequest}/bon', [TransferRequestController::class, 'bon'])->name('transfer-requests.bon');
        Route::get('/transfer-requests/{transferRequest}/bon/download', [TransferRequestController::class, 'bonDownload'])->name('transfer-requests.bon.download');
        Route::get('/transfer-requests/{transferRequest}/bon/preview', [TransferRequestController::class, 'bonPreview'])->name('transfer-requests.bon.preview');

        Route::patch('/warehouses/{warehouse}/replenishment', [WarehouseReplenishmentController::class, 'update'])->name('warehouses.replenishment.update');

        Route::post('/reservations', [InventoryReservationController::class, 'store'])->name('reservations.store');
        Route::post('/reservations/{reservation}/release', [InventoryReservationController::class, 'release'])->name('reservations.release');
        Route::post('/reservations/{reservation}/consume', [InventoryReservationController::class, 'consume'])->name('reservations.consume');
    });

    Route::prefix('integrations')->name('integrations.')->group(function () {
        Route::get('/woocommerce', [WooCommerceIntegrationController::class, 'index'])->name('woocommerce.index');
        Route::post('/woocommerce', [WooCommerceIntegrationController::class, 'store'])->name('woocommerce.store');
        Route::patch('/woocommerce/{integration}', [WooCommerceIntegrationController::class, 'update'])->name('woocommerce.update');
        Route::post('/woocommerce/{integration}/test', [WooCommerceIntegrationController::class, 'test'])->name('woocommerce.test');
        Route::post('/woocommerce/{integration}/sync', [WooCommerceIntegrationController::class, 'sync'])->name('woocommerce.sync');
        Route::get('/woocommerce/{integration}/status', [WooCommerceIntegrationController::class, 'runStatus'])->name('woocommerce.status');
    });

    Route::get('/financial-accounts', [FinancialAccountController::class, 'index'])->name('financial-accounts.index');
    Route::post('/financial-accounts', [FinancialAccountController::class, 'store'])->name('financial-accounts.store');
    Route::patch('/financial-accounts/{financialAccount}', [FinancialAccountController::class, 'update'])->name('financial-accounts.update');

    Route::prefix('payments')->name('payments.')->group(function () {
        Route::get('/', [PaymentController::class, 'index'])->name('index');
        Route::get('/{payment}', [PaymentController::class, 'show'])->name('show');
        Route::post('/{payment}/reverse', [PaymentController::class, 'reverse'])->name('reverse');
    });

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/print', [DocumentRenderingController::class, 'printInvoice'])->name('invoices.print');
    Route::get('/invoices/{invoice}/pdf', [DocumentRenderingController::class, 'invoicePdf'])->name('invoices.pdf');
    Route::get('/invoices/{invoice}/download', [DocumentRenderingController::class, 'downloadInvoice'])->name('invoices.download');
    Route::post('/invoices/{invoice}/email', [DocumentEmailController::class, 'invoice'])->name('invoices.email');
    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::post('/invoices/{invoice}/corrections', [InvoiceController::class, 'correct'])->name('invoices.corrections.store');

    // Financial-line editing — only ever reaches a post-issue correction Draft
    // (enforced by the `editLines` Invoice policy inside the controller).
    Route::get('/invoices/{invoice}/correction-lines/search', [InvoiceCorrectionLineController::class, 'search'])->name('invoices.correction-lines.search');
    Route::post('/invoices/{invoice}/correction-lines', [InvoiceCorrectionLineController::class, 'store'])->name('invoices.correction-lines.store');
    Route::patch('/invoices/{invoice}/correction-lines/{line}', [InvoiceCorrectionLineController::class, 'update'])->name('invoices.correction-lines.update');
    Route::delete('/invoices/{invoice}/correction-lines/{line}', [InvoiceCorrectionLineController::class, 'destroy'])->name('invoices.correction-lines.destroy');

    // Quotations / Devis — commercial proposals. Zero Inventory / Finance impact.
    Route::get('/quotations', [QuotationController::class, 'index'])->name('quotations.index');
    Route::get('/quotations/create', [QuotationController::class, 'create'])->name('quotations.create');
    Route::get('/quotations/customer-search', [QuotationController::class, 'customerSearch'])->name('quotations.customer-search');
    Route::post('/quotations/customers', [QuotationController::class, 'storeCustomer'])->name('quotations.customers.store');
    Route::post('/quotations', [QuotationController::class, 'store'])->name('quotations.store');
    Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show');
    Route::get('/quotations/{quotation}/print', [QuotationRenderingController::class, 'print'])->name('quotations.print');
    Route::get('/quotations/{quotation}/pdf', [QuotationRenderingController::class, 'pdf'])->name('quotations.pdf');
    Route::get('/quotations/{quotation}/download', [QuotationRenderingController::class, 'download'])->name('quotations.download');
    Route::get('/quotations/{quotation}/search', [QuotationController::class, 'search'])->name('quotations.search');
    Route::patch('/quotations/{quotation}', [QuotationController::class, 'update'])->name('quotations.update');
    Route::post('/quotations/{quotation}/issue', [QuotationController::class, 'issue'])->name('quotations.issue');
    Route::post('/quotations/{quotation}/accept', [QuotationController::class, 'accept'])->name('quotations.accept');
    Route::post('/quotations/{quotation}/reject', [QuotationController::class, 'reject'])->name('quotations.reject');
    Route::post('/quotations/{quotation}/duplicate', [QuotationController::class, 'duplicate'])->name('quotations.duplicate');
    Route::post('/quotations/{quotation}/revise', [QuotationController::class, 'revise'])->name('quotations.revise');
    Route::post('/quotations/{quotation}/email', [QuotationEmailController::class, 'send'])->name('quotations.email');
    Route::post('/quotations/{quotation}/conversion', [QuotationConversionController::class, 'store'])->name('quotations.conversion.store');
    Route::post('/quotations/{quotation}/lines', [QuotationLineController::class, 'store'])->name('quotations.lines.store');
    Route::patch('/quotations/{quotation}/lines/{line}', [QuotationLineController::class, 'update'])->name('quotations.lines.update');
    Route::delete('/quotations/{quotation}/lines/{line}', [QuotationLineController::class, 'destroy'])->name('quotations.lines.destroy');

    Route::get('/delivery-notes', [DeliveryNoteController::class, 'index'])->name('delivery-notes.index');
    Route::get('/delivery-notes/{deliveryNote}', [DeliveryNoteController::class, 'show'])->name('delivery-notes.show');
    Route::get('/delivery-notes/{deliveryNote}/print', [DocumentRenderingController::class, 'printDeliveryNote'])->name('delivery-notes.print');
    Route::get('/delivery-notes/{deliveryNote}/pdf', [DocumentRenderingController::class, 'deliveryNotePdf'])->name('delivery-notes.pdf');
    Route::get('/delivery-notes/{deliveryNote}/download', [DocumentRenderingController::class, 'downloadDeliveryNote'])->name('delivery-notes.download');
    Route::post('/delivery-notes/{deliveryNote}/email', [DocumentEmailController::class, 'deliveryNote'])->name('delivery-notes.email');
    Route::patch('/delivery-notes/{deliveryNote}', [DeliveryNoteController::class, 'update'])->name('delivery-notes.update');
    Route::post('/delivery-notes/{deliveryNote}/issue', [DeliveryNoteController::class, 'issue'])->name('delivery-notes.issue');
    Route::post('/delivery-notes/{deliveryNote}/cancel', [DeliveryNoteController::class, 'cancel'])->name('delivery-notes.cancel');

    Route::prefix('sales')->name('sales.')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');

        Route::get('/orders', [SalesOrderController::class, 'index'])->name('orders.index');
        // Manual order creation is intentionally not exposed in the UI — Sales Orders
        // originate from the POS. `store` is retained for internal/admin/test use.
        Route::post('/orders', [SalesOrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{order}', [SalesOrderController::class, 'show'])->name('orders.show');
        Route::get('/orders/{order}/edit', [SalesOrderController::class, 'edit'])->name('orders.edit');
        Route::get('/orders/{order}/line-search', [SalesOrderController::class, 'lineSearch'])->name('orders.line-search');
        Route::get('/orders/{order}/customer-search', [SalesOrderController::class, 'customerSearch'])->name('orders.customer-search');
        Route::patch('/orders/{order}', [SalesOrderController::class, 'update'])->name('orders.update');
        Route::post('/orders/{order}/lines', [SalesOrderLineController::class, 'store'])->name('orders.lines.store');
        Route::patch('/orders/{order}/lines/{lineId}', [SalesOrderLineController::class, 'update'])->whereNumber('lineId')->name('orders.lines.update');
        Route::delete('/orders/{order}/lines/{lineId}', [SalesOrderLineController::class, 'destroy'])->whereNumber('lineId')->name('orders.lines.destroy');
        Route::post('/orders/{order}/confirm', [SalesOrderLifecycleController::class, 'confirm'])->name('orders.confirm');
        Route::post('/orders/{order}/cancel', [SalesOrderLifecycleController::class, 'cancel'])->name('orders.cancel');
        Route::post('/orders/{order}/fulfill', [SalesOrderLifecycleController::class, 'fulfill'])->name('orders.fulfill');
        Route::post('/orders/{order}/payments', [PaymentController::class, 'store'])->name('orders.payments.store');
        Route::post('/orders/{order}/invoices', [InvoiceController::class, 'store'])->name('orders.invoices.store');
        Route::post('/orders/{order}/delivery-notes', [DeliveryNoteController::class, 'store'])->name('orders.delivery-notes.store');
        // Raise a supplier special-order for one under-covered catalogue line.
        Route::post('/orders/{order}/procurements', [ProcurementController::class, 'store'])->name('orders.procurements.store');
    });

    // Achats — supplier procurement / special orders. Distinct domain from the
    // internal warehouse Transfer Requests above.
    Route::prefix('procurement')->name('procurement.')->group(function () {
        Route::get('/', [ProcurementController::class, 'index'])->name('index');
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::patch('/{procurement}/availability', [ProcurementController::class, 'availability'])->name('availability');
        Route::patch('/{procurement}/supplier', [ProcurementController::class, 'changeSupplier'])->name('supplier');
        Route::post('/{procurement}/order', [ProcurementController::class, 'order'])->name('order');
        Route::post('/{procurement}/receive', [ProcurementController::class, 'receive'])->name('receive');
        Route::post('/{procurement}/cancel', [ProcurementController::class, 'cancel'])->name('cancel');
    });
});

// Public, signature-gated read of one issued Invoice PDF (used for WhatsApp/email
// sharing). Not behind `auth`: the temporary signature is the authorisation and
// binds the URL to a single invoice id + expiry.
Route::get('/invoices/{invoice}/shared-pdf', [DocumentRenderingController::class, 'sharedInvoicePdf'])
    ->middleware('signed')
    ->name('invoices.shared-pdf');

// Public, signature-gated read of one issued Devis PDF (WhatsApp / email sharing).
Route::get('/quotations/{quotation}/shared-pdf', [QuotationRenderingController::class, 'shared'])
    ->middleware('signed')
    ->name('quotations.shared-pdf');

<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Catalog\BrandController;
use App\Http\Controllers\Catalog\CategoryController;
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
use App\Http\Controllers\Inventory\InventoryMovementController;
use App\Http\Controllers\Inventory\InventoryReservationController;
use App\Http\Controllers\Inventory\InventoryStockController;
use App\Http\Controllers\Inventory\StockTransferController;
use App\Http\Controllers\Inventory\WarehouseController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationMembershipController;
use App\Http\Controllers\Payments\FinancialAccountController;
use App\Http\Controllers\Payments\PaymentController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PosController;
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
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/platform', PlatformController::class)->name('platform.index');
    Route::get('/document-profile', [DocumentProfileController::class, 'edit'])->name('document-profile.edit');
    Route::put('/document-profile', [DocumentProfileController::class, 'update'])->name('document-profile.update');

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

        Route::post('/reservations', [InventoryReservationController::class, 'store'])->name('reservations.store');
        Route::post('/reservations/{reservation}/release', [InventoryReservationController::class, 'release'])->name('reservations.release');
        Route::post('/reservations/{reservation}/consume', [InventoryReservationController::class, 'consume'])->name('reservations.consume');
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
        Route::get('/orders/create', [SalesOrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [SalesOrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{order}', [SalesOrderController::class, 'show'])->name('orders.show');
        Route::get('/orders/{order}/edit', [SalesOrderController::class, 'edit'])->name('orders.edit');
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
    });
});

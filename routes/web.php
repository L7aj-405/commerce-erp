<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Catalog\BrandController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ProductVariantController;
use App\Http\Controllers\Catalog\TaxRateController;
use App\Http\Controllers\Catalog\UnitOfMeasureController;
use App\Http\Controllers\Inventory\InventoryMovementController;
use App\Http\Controllers\Inventory\InventoryReservationController;
use App\Http\Controllers\Inventory\InventoryStockController;
use App\Http\Controllers\Inventory\WarehouseController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationMembershipController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\RoleController;
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

        Route::post('/reservations', [InventoryReservationController::class, 'store'])->name('reservations.store');
        Route::post('/reservations/{reservation}/release', [InventoryReservationController::class, 'release'])->name('reservations.release');
        Route::post('/reservations/{reservation}/consume', [InventoryReservationController::class, 'consume'])->name('reservations.consume');
    });
});

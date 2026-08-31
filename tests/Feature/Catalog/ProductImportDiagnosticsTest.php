<?php

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\CreateProductAction;
use App\Actions\Inventory\OpeningStockAction;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\ProductImportCorrectionTestCase;

class ProductImportDiagnosticsTest extends ProductImportCorrectionTestCase
{
    public function test_duplicate_sku_has_a_persistent_human_readable_row_reason(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->createProduct($organization, 'Existing microphone', 'SM58');
        $import = $this->stageBusinessFile($owner, '7001,,Duplicate,,SM58,100.00,,instock,0,Shure');

        $this->previewBusinessFile($owner, $import);
        $this->assertDatabaseHas('product_import_rows', [
            'product_import_id' => $import->id,
            'status' => 'skipped',
            'error_code' => 'duplicate_sku',
            'error_phase' => 'preview',
            'error_message' => 'Le SKU "SM58" existe déjà.',
        ]);
        $this->confirmBusinessFile($owner, $import);

        $this->actingAs($owner)->get(route('catalog.product-imports.show', $import))->assertInertia(fn (Assert $page) => $page
            ->where('productImport.skipped_count', 1)
            ->has('resultRows', 1, fn (Assert $row) => $row
                ->where('error_message', 'Le SKU "SM58" existe déjà.')
                ->where('error_phase', 'preview')
                ->etc()));
    }

    public function test_invalid_price_is_visible_during_preview_and_after_zero_success_confirmation(): void
    {
        $owner = User::factory()->create();
        $this->createOrganization($owner);
        $import = $this->stageBusinessFile($owner, '7002,,Bad Price,,BAD-PRICE,ABC,,instock,0,Brand');

        $this->previewBusinessFile($owner, $import);
        $this->assertDatabaseHas('product_import_rows', [
            'product_import_id' => $import->id,
            'status' => 'error',
            'error_code' => 'invalid_regular_price',
            'error_message' => 'Le prix "ABC" n’est pas valide.',
        ]);
        $this->confirmBusinessFile($owner, $import);

        $this->assertDatabaseHas('product_imports', [
            'id' => $import->id,
            'status' => 'failed',
            'created_product_count' => 0,
            'failed_count' => 1,
            'global_error_code' => 'no_rows_imported',
        ]);
        $this->actingAs($owner)->get(route('catalog.product-imports.show', $import))->assertInertia(fn (Assert $page) => $page
            ->where('productImport.global_error_message', 'Aucun produit n’a pu être importé. Consultez les erreurs par ligne ci-dessous.')
            ->where('resultRows.0.error_message', 'Le prix "ABC" n’est pas valide.')
            ->etc());
    }

    public function test_missing_warehouse_is_persisted_as_a_global_configuration_error(): void
    {
        $owner = User::factory()->create();
        $this->createOrganization($owner);
        $import = $this->stageBusinessFile($owner, '7003,,Stock Product,,STOCK-DIAG,10.00,,instock,2,Brand');

        $this->actingAs($owner)->put(route('catalog.product-imports.preview', $import), [
            'mapping' => $this->businessMapping(),
            'defaults' => ['stock_mode' => 'import'],
        ])->assertSessionHasErrors('defaults.warehouse_id');

        $this->assertDatabaseHas('product_imports', [
            'id' => $import->id,
            'global_error_code' => 'missing_warehouse',
            'global_error_message' => 'Sélectionnez un emplacement pour importer le stock.',
        ]);
        $this->actingAs($owner)->get(route('catalog.product-imports.show', $import))->assertInertia(fn (Assert $page) => $page
            ->where('productImport.global_error_code', 'missing_warehouse')
            ->where('productImport.global_error_message', 'Sélectionnez un emplacement pour importer le stock.')
            ->etc());
    }

    public function test_opening_stock_failure_is_presented_as_a_runtime_row_error(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $import = $this->stageBusinessFile($owner, '7004,,Stock Failure,,STOCK-FAIL,10.00,,instock,2,Brand');
        $this->previewBusinessFile($owner, $import, ['stock_mode' => 'import', 'warehouse_id' => $warehouse->id]);
        $this->mock(OpeningStockAction::class, function (MockInterface $mock) {
            $mock->shouldReceive('execute')->once()->andThrow(ValidationException::withMessages([
                'quantity' => 'Opening stock cannot be applied.',
            ]));
        });

        $this->confirmBusinessFile($owner, $import);

        $this->assertDatabaseHas('product_import_rows', [
            'product_import_id' => $import->id,
            'status' => 'error',
            'error_code' => 'invalid_stock_quantity',
            'error_phase' => 'runtime',
            'error_message' => 'La quantité "2.0000" n’est pas valide pour le stock initial.',
        ]);
        $this->assertDatabaseMissing('products', ['organization_id' => $organization->id, 'name' => 'Stock Failure']);
        $this->actingAs($owner)->get(route('catalog.product-imports.show', $import))->assertInertia(fn (Assert $page) => $page
            ->where('resultRows.0.error_phase', 'runtime')
            ->where('resultRows.0.error_message', 'La quantité "2.0000" n’est pas valide pour le stock initial.')
            ->etc());
    }

    public function test_unexpected_exception_is_safely_presented_and_structurally_logged(): void
    {
        Log::spy();
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $import = $this->stageBusinessFile($owner, '7005,,Technical Failure,,TECH-FAIL,10.00,,instock,0,Brand');
        $this->previewBusinessFile($owner, $import);
        $this->mock(CreateProductAction::class, function (MockInterface $mock) {
            $mock->shouldReceive('execute')->once()->andThrow(new RuntimeException('SQLSTATE[23000] at C:\\private\\catalog.sql'));
        });

        $this->confirmBusinessFile($owner, $import);

        $this->assertDatabaseHas('product_import_rows', [
            'product_import_id' => $import->id,
            'error_code' => 'technical_error',
            'error_message' => 'Une erreur technique a empêché l’import de cette ligne.',
        ]);
        Log::shouldHaveReceived('error')->withArgs(fn (string $event, array $context) => $event === 'product_import.row_failed'
            && $context['import_id'] === $import->id
            && $context['organization_id'] === $organization->id
            && $context['row_number'] === 2
            && $context['exception_class'] === RuntimeException::class
            && str_contains($context['exception_message'], 'SQLSTATE'));

        $response = $this->actingAs($owner)->get(route('catalog.product-imports.show', $import));
        $response->assertInertia(fn (Assert $page) => $page
            ->where('resultRows.0.error_message', 'Une erreur technique a empêché l’import de cette ligne.')
            ->where('resultRows.0.error_code', 'technical_error')
            ->etc());
        $response->assertDontSee('SQLSTATE')->assertDontSee('catalog.sql')->assertDontSee(RuntimeException::class);
    }

    public function test_partial_import_reports_imported_skipped_and_failed_counts(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->createProduct($organization, 'Existing product', 'DUPLICATE-ROW');
        $rows = implode("\n", [
            '7006,,Valid Product,,VALID-ROW,10.00,,instock,0,Brand',
            '7007,,Invalid Product,,INVALID-ROW,not-a-price,,instock,0,Brand',
            '7008,,Duplicate Product,,DUPLICATE-ROW,10.00,,instock,0,Brand',
        ]);
        $import = $this->stageBusinessFile($owner, $rows);

        $this->previewBusinessFile($owner, $import);
        $this->confirmBusinessFile($owner, $import);

        $this->assertDatabaseHas('product_imports', [
            'id' => $import->id,
            'status' => 'completed_with_errors',
            'created_product_count' => 1,
            'skipped_count' => 1,
            'failed_count' => 1,
        ]);
        $this->actingAs($owner)->get(route('catalog.product-imports.show', $import))->assertInertia(fn (Assert $page) => $page
            ->where('productImport.created_product_count', 1)
            ->where('productImport.skipped_count', 1)
            ->where('productImport.failed_count', 1)
            ->where('productImport.status', 'completed_with_errors')
            ->has('resultRows')
            ->etc());
    }
}

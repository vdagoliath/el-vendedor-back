<?php

use App\Models\Business;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\Warehouse;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->withoutVite();
});

function seedAdjustmentWarehouse(Business $business, string $externalId, string $name): Warehouse
{
    return Warehouse::query()->create([
        'business_id' => $business->id,
        'external_id' => $externalId,
        'name' => $name,
    ]);
}

function seedAdjustmentProduct(Business $business, string $externalId, string $title, ?string $code = null): Product
{
    return Product::query()->create([
        'business_id' => $business->id,
        'external_id' => $externalId,
        'title' => $title,
        'code' => $code ?? $externalId,
        'type' => 'goods',
    ]);
}

function seedStockAdjustment(Business $business, array $attrs): StockAdjustment
{
    return StockAdjustment::query()->create(array_merge([
        'business_id' => $business->id,
        'external_id' => 'adj-'.uniqid(),
        'target_quantity' => 0,
        'change_quantity' => 0,
        'adjustment_at' => now(),
    ], $attrs));
}

test('analytics user sees paginated stock adjustments with names resolved', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();
    $business = Business::factory()->create();
    $superAdmin->switchCurrentBusiness($business);

    $main = seedAdjustmentWarehouse($business, 'wh-main', 'Almacén principal');
    seedAdjustmentProduct($business, 'prod-cafe', 'Café molido', 'CAF-1');

    seedStockAdjustment($business, [
        'product_external_id' => 'prod-cafe',
        'warehouse_external_id' => $main->external_id,
        'previous_quantity' => 10,
        'target_quantity' => 7,
        'change_quantity' => -3,
        'reason' => 'merma',
        'adjustment_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($superAdmin)->get(route('backoffice.stock-adjustments.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('backoffice/StockAdjustments')
        ->where('stats.count', 1)
        ->where('stats.total_change', -3)
        ->where('adjustments.data.0.product.title', 'Café molido')
        ->where('adjustments.data.0.warehouse.name', 'Almacén principal')
        ->where('adjustments.data.0.change_quantity', -3)
        ->where('adjustments.data.0.target_quantity', 7)
        ->where('adjustments.data.0.previous_quantity', 10)
        ->where('adjustments.data.0.reason', 'merma')
    );
});

test('stock adjustments respect warehouse, reason and search filters', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();
    $business = Business::factory()->create();
    $superAdmin->switchCurrentBusiness($business);

    $main = seedAdjustmentWarehouse($business, 'wh-main', 'Principal');
    $aux = seedAdjustmentWarehouse($business, 'wh-aux', 'Auxiliar');

    seedAdjustmentProduct($business, 'prod-a', 'Aceite');
    seedAdjustmentProduct($business, 'prod-b', 'Pan');

    seedStockAdjustment($business, [
        'product_external_id' => 'prod-a',
        'warehouse_external_id' => $main->external_id,
        'target_quantity' => 5,
        'change_quantity' => 2,
        'reason' => 'inventario fisico',
    ]);

    seedStockAdjustment($business, [
        'product_external_id' => 'prod-b',
        'warehouse_external_id' => $aux->external_id,
        'target_quantity' => 0,
        'change_quantity' => -1,
        'reason' => 'merma',
    ]);

    $warehouseResponse = $this->actingAs($superAdmin)
        ->get(route('backoffice.stock-adjustments.index', ['warehouse' => $main->external_id]));

    $warehouseResponse->assertOk();
    $warehouseResponse->assertInertia(fn ($page) => $page
        ->where('stats.count', 1)
        ->where('adjustments.data.0.product.title', 'Aceite')
    );

    $reasonResponse = $this->actingAs($superAdmin)
        ->get(route('backoffice.stock-adjustments.index', ['reason' => 'merma']));

    $reasonResponse->assertOk();
    $reasonResponse->assertInertia(fn ($page) => $page
        ->where('stats.count', 1)
        ->where('adjustments.data.0.product.title', 'Pan')
    );

    $searchResponse = $this->actingAs($superAdmin)
        ->get(route('backoffice.stock-adjustments.index', ['search' => 'aceite']));

    $searchResponse->assertOk();
    $searchResponse->assertInertia(fn ($page) => $page
        ->where('stats.count', 1)
        ->where('adjustments.data.0.product.title', 'Aceite')
    );
});

test('export downloads xlsx with adjustments and product summary sheets', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();
    $business = Business::factory()->create();
    $superAdmin->switchCurrentBusiness($business);

    $main = seedAdjustmentWarehouse($business, 'wh-main', 'Principal');
    seedAdjustmentProduct($business, 'prod-a', 'Aceite', 'AC-1');

    seedStockAdjustment($business, [
        'product_external_id' => 'prod-a',
        'warehouse_external_id' => $main->external_id,
        'previous_quantity' => 10,
        'target_quantity' => 12,
        'change_quantity' => 2,
        'reason' => 'recuento',
    ]);

    seedStockAdjustment($business, [
        'product_external_id' => 'prod-a',
        'warehouse_external_id' => $main->external_id,
        'previous_quantity' => 12,
        'target_quantity' => 9,
        'change_quantity' => -3,
        'reason' => 'merma',
    ]);

    $response = $this->actingAs($superAdmin)->get(route('backoffice.stock-adjustments.export'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $tempPath = tempnam(sys_get_temp_dir(), 'adjustments-export-').'.xlsx';
    file_put_contents($tempPath, $response->streamedContent());

    try {
        $spreadsheet = IOFactory::load($tempPath);

        expect($spreadsheet->getSheetNames())->toBe(['Ajustes', 'Resumen por producto']);

        $adjustments = $spreadsheet->getSheetByName('Ajustes')->toArray();
        expect($adjustments[0])->toBe([
            'Fecha', 'Producto', 'Código', 'Almacén', 'Cantidad previa', 'Cantidad objetivo', 'Diferencia', 'Razón',
        ]);
        expect(count($adjustments))->toBe(3);

        $summary = $spreadsheet->getSheetByName('Resumen por producto')->toArray();
        expect($summary[0])->toBe(['Producto', 'Almacén', 'Ajustes', 'Diferencia total']);
        expect($summary[1][0])->toBe('Aceite');
        expect($summary[1][1])->toBe('Principal');
        expect((int) $summary[1][2])->toBe(2);
        expect((float) $summary[1][3])->toBe(-1.0);
    } finally {
        @unlink($tempPath);
    }
});

test('users without analytics access can not view stock adjustments', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();
    $implementer->switchCurrentBusiness($business);

    $this->actingAs($implementer)
        ->get(route('backoffice.stock-adjustments.index'))
        ->assertForbidden();

    $this->actingAs($implementer)
        ->get(route('backoffice.stock-adjustments.export'))
        ->assertForbidden();
});

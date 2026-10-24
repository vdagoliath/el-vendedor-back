<?php

use App\Models\Business;
use App\Models\Product;
use App\Models\StockProjection;
use App\Models\User;
use App\Models\Warehouse;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->withoutVite();
});

function makeWarehouse(Business $business, string $externalId, string $name): Warehouse
{
    return Warehouse::query()->create([
        'business_id' => $business->id,
        'external_id' => $externalId,
        'name' => $name,
    ]);
}

function makeProduct(Business $business, string $externalId, string $title, ?float $minStock = null): Product
{
    return Product::query()->create([
        'business_id' => $business->id,
        'external_id' => $externalId,
        'title' => $title,
        'code' => $externalId,
        'type' => 'goods',
        'min_stock' => $minStock,
    ]);
}

function makeStockProjection(Business $business, string $productId, string $warehouseId, float $qty): StockProjection
{
    return StockProjection::query()->create([
        'business_id' => $business->id,
        'product_external_id' => $productId,
        'warehouse_external_id' => $warehouseId,
        'qty' => $qty,
    ]);
}

test('analytics user sees global inventory matrix with per-warehouse breakdown', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();
    $business = Business::factory()->create();
    $superAdmin->switchCurrentBusiness($business);

    $main = makeWarehouse($business, 'wh-main', 'Almacén principal');
    $aux = makeWarehouse($business, 'wh-aux', 'Almacén auxiliar');

    makeProduct($business, 'prod-cafe', 'Café molido');
    makeProduct($business, 'prod-pan', 'Pan', minStock: 10);

    makeStockProjection($business, 'prod-cafe', $main->external_id, 12);
    makeStockProjection($business, 'prod-cafe', $aux->external_id, 8);
    makeStockProjection($business, 'prod-pan', $main->external_id, 3);

    $response = $this->actingAs($superAdmin)->get(route('backoffice.inventory.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('backoffice/Inventory')
        ->where('stats.product_count', 2)
        ->where('stats.critical_count', 1)
        ->where('warehouses.0.name', 'Almacén auxiliar')
        ->where('warehouses.1.name', 'Almacén principal')
        ->where('inventory.data.0.product_title', 'Café molido')
        ->where('inventory.data.0.total', 20)
        ->where('inventory.data.0.by_warehouse.wh-main', 12)
        ->where('inventory.data.0.by_warehouse.wh-aux', 8)
        ->where('inventory.data.1.product_title', 'Pan')
        ->where('inventory.data.1.is_critical', true)
    );
});

test('inventory respects warehouse, search, stock and critical filters', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();
    $business = Business::factory()->create();
    $superAdmin->switchCurrentBusiness($business);

    $main = makeWarehouse($business, 'wh-main', 'Principal');
    $aux = makeWarehouse($business, 'wh-aux', 'Auxiliar');

    makeProduct($business, 'prod-cafe', 'Café', minStock: 100);
    makeProduct($business, 'prod-pan', 'Pan', minStock: 5);
    makeProduct($business, 'prod-azucar', 'Azúcar');

    makeStockProjection($business, 'prod-cafe', $main->external_id, 5);
    makeStockProjection($business, 'prod-pan', $aux->external_id, 30);

    $byWarehouse = $this->actingAs($superAdmin)
        ->get(route('backoffice.inventory.index', ['warehouse' => $main->external_id]));
    $byWarehouse->assertOk();
    $byWarehouse->assertInertia(fn ($page) => $page
        ->where('stats.product_count', 1)
        ->where('inventory.data.0.product_title', 'Café')
    );

    $bySearch = $this->actingAs($superAdmin)
        ->get(route('backoffice.inventory.index', ['search' => 'azucar']));
    $bySearch->assertOk();
    $bySearch->assertInertia(fn ($page) => $page
        ->where('stats.product_count', 1)
        ->where('inventory.data.0.product_title', 'Azúcar')
    );

    $onlyWithStock = $this->actingAs($superAdmin)
        ->get(route('backoffice.inventory.index', ['only_with_stock' => 1]));
    $onlyWithStock->assertOk();
    $onlyWithStock->assertInertia(fn ($page) => $page
        ->where('stats.product_count', 2)
    );

    $onlyCritical = $this->actingAs($superAdmin)
        ->get(route('backoffice.inventory.index', ['only_critical' => 1]));
    $onlyCritical->assertOk();
    $onlyCritical->assertInertia(fn ($page) => $page
        ->where('stats.product_count', 1)
        ->where('inventory.data.0.product_title', 'Café')
    );
});

test('inventory export produces xlsx with global and per-warehouse sheets', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();
    $business = Business::factory()->create();
    $superAdmin->switchCurrentBusiness($business);

    $main = makeWarehouse($business, 'wh-main', 'Principal');
    $aux = makeWarehouse($business, 'wh-aux', 'Auxiliar');

    makeProduct($business, 'prod-cafe', 'Café');
    makeProduct($business, 'prod-pan', 'Pan');

    makeStockProjection($business, 'prod-cafe', $main->external_id, 12);
    makeStockProjection($business, 'prod-cafe', $aux->external_id, 8);
    makeStockProjection($business, 'prod-pan', $main->external_id, 4);

    $response = $this->actingAs($superAdmin)->get(route('backoffice.inventory.export'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $tempPath = tempnam(sys_get_temp_dir(), 'inventory-export-').'.xlsx';
    file_put_contents($tempPath, $response->streamedContent());

    try {
        $spreadsheet = IOFactory::load($tempPath);
        expect($spreadsheet->getSheetNames())->toBe(['Inventario global', 'Por almacén']);

        $global = $spreadsheet->getSheetByName('Inventario global')->toArray();
        expect($global[0])->toBe(['Producto', 'Código', 'Auxiliar', 'Principal', 'Total', 'Stock mínimo']);

        $cafeRow = collect(array_slice($global, 1))->firstWhere(0, 'Café');
        expect((float) $cafeRow[2])->toBe(8.0); // Auxiliar
        expect((float) $cafeRow[3])->toBe(12.0); // Principal
        expect((float) $cafeRow[4])->toBe(20.0); // Total

        $perWarehouse = $spreadsheet->getSheetByName('Por almacén')->toArray();
        expect($perWarehouse[0])->toBe(['Almacén', 'Producto', 'Código', 'Cantidad']);

        $rows = collect(array_slice($perWarehouse, 1));
        expect((float) ($rows->where(0, 'Principal')->where(1, 'Café')->first()[3] ?? 0))->toBe(12.0);
        expect((float) ($rows->where(0, 'Auxiliar')->where(1, 'Café')->first()[3] ?? 0))->toBe(8.0);
        expect((float) ($rows->where(0, 'Principal')->where(1, 'Pan')->first()[3] ?? 0))->toBe(4.0);
        // Products with zero stock in a warehouse should not produce rows there.
        expect($rows->where(0, 'Auxiliar')->where(1, 'Pan')->first())->toBeNull();
    } finally {
        @unlink($tempPath);
    }
});

test('users without analytics access can not view inventory', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();
    $implementer->switchCurrentBusiness($business);

    $this->actingAs($implementer)
        ->get(route('backoffice.inventory.index'))
        ->assertForbidden();

    $this->actingAs($implementer)
        ->get(route('backoffice.inventory.export'))
        ->assertForbidden();
});

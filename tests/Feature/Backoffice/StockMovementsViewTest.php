<?php

use App\Models\Business;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->withoutVite();
});

function seedWarehouse(Business $business, string $externalId, string $name): Warehouse
{
    return Warehouse::query()->create([
        'business_id' => $business->id,
        'external_id' => $externalId,
        'name' => $name,
    ]);
}

function seedProduct(Business $business, string $externalId, string $title, ?string $code = null): Product
{
    return Product::query()->create([
        'business_id' => $business->id,
        'external_id' => $externalId,
        'title' => $title,
        'code' => $code ?? $externalId,
        'type' => 'goods',
    ]);
}

function seedStockMovement(Business $business, array $attrs): StockMovement
{
    return StockMovement::query()->create(array_merge([
        'business_id' => $business->id,
        'external_id' => 'mov-'.uniqid(),
        'quantity' => 1,
        'movement_at' => now(),
    ], $attrs));
}

test('analytics user sees paginated stock movements with warehouse names resolved', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();
    $business = Business::factory()->create();
    $superAdmin->switchCurrentBusiness($business);

    $main = seedWarehouse($business, 'wh-main', 'Almacén principal');
    $aux = seedWarehouse($business, 'wh-aux', 'Almacén auxiliar');
    seedProduct($business, 'prod-cafe', 'Café molido', 'CAF-1');

    seedStockMovement($business, [
        'product_external_id' => 'prod-cafe',
        'from_warehouse_external_id' => $main->external_id,
        'to_warehouse_external_id' => $aux->external_id,
        'quantity' => 5,
        'movement_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($superAdmin)->get(route('backoffice.stock-movements.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('backoffice/StockMovements')
        ->where('stats.count', 1)
        ->where('stats.total_quantity', 5)
        ->where('movements.data.0.product.title', 'Café molido')
        ->where('movements.data.0.from_warehouse.name', 'Almacén principal')
        ->where('movements.data.0.to_warehouse.name', 'Almacén auxiliar')
        ->where('movements.data.0.quantity', 5)
        ->where('warehouses.0.name', 'Almacén auxiliar')
    );
});

test('stock movements respect warehouse and search filters', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();
    $business = Business::factory()->create();
    $superAdmin->switchCurrentBusiness($business);

    $main = seedWarehouse($business, 'wh-main', 'Principal');
    $aux = seedWarehouse($business, 'wh-aux', 'Auxiliar');
    $other = seedWarehouse($business, 'wh-other', 'Otro');

    seedProduct($business, 'prod-a', 'Aceite');
    seedProduct($business, 'prod-b', 'Pan');

    seedStockMovement($business, [
        'product_external_id' => 'prod-a',
        'from_warehouse_external_id' => $main->external_id,
        'to_warehouse_external_id' => $aux->external_id,
        'quantity' => 2,
    ]);

    seedStockMovement($business, [
        'product_external_id' => 'prod-b',
        'from_warehouse_external_id' => $main->external_id,
        'to_warehouse_external_id' => $other->external_id,
        'quantity' => 3,
    ]);

    $response = $this->actingAs($superAdmin)
        ->get(route('backoffice.stock-movements.index', ['to' => $aux->external_id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('stats.count', 1)
        ->where('movements.data.0.product.title', 'Aceite')
    );

    $searchResponse = $this->actingAs($superAdmin)
        ->get(route('backoffice.stock-movements.index', ['search' => 'pan']));

    $searchResponse->assertOk();
    $searchResponse->assertInertia(fn ($page) => $page
        ->where('stats.count', 1)
        ->where('movements.data.0.product.title', 'Pan')
    );
});

test('export downloads xlsx with movements and route summary sheets', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();
    $business = Business::factory()->create();
    $superAdmin->switchCurrentBusiness($business);

    $main = seedWarehouse($business, 'wh-main', 'Principal');
    $aux = seedWarehouse($business, 'wh-aux', 'Auxiliar');
    seedProduct($business, 'prod-a', 'Aceite', 'AC-1');

    seedStockMovement($business, [
        'product_external_id' => 'prod-a',
        'from_warehouse_external_id' => $main->external_id,
        'to_warehouse_external_id' => $aux->external_id,
        'quantity' => 2,
    ]);

    seedStockMovement($business, [
        'product_external_id' => 'prod-a',
        'from_warehouse_external_id' => $main->external_id,
        'to_warehouse_external_id' => $aux->external_id,
        'quantity' => 4,
    ]);

    $response = $this->actingAs($superAdmin)->get(route('backoffice.stock-movements.export'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $tempPath = tempnam(sys_get_temp_dir(), 'stock-export-').'.xlsx';
    file_put_contents($tempPath, $response->streamedContent());

    try {
        $spreadsheet = IOFactory::load($tempPath);

        expect($spreadsheet->getSheetNames())->toBe(['Movimientos', 'Resumen por ruta']);

        $movements = $spreadsheet->getSheetByName('Movimientos')->toArray();
        expect($movements[0])->toBe(['Fecha', 'Producto', 'Código', 'Origen', 'Destino', 'Cantidad']);
        expect(count($movements))->toBe(3);

        $summary = $spreadsheet->getSheetByName('Resumen por ruta')->toArray();
        expect($summary[0])->toBe(['Origen', 'Destino', 'Movimientos', 'Cantidad total']);
        expect($summary[1][0])->toBe('Principal');
        expect($summary[1][1])->toBe('Auxiliar');
        expect((int) $summary[1][2])->toBe(2);
        expect((float) $summary[1][3])->toBe(6.0);
    } finally {
        @unlink($tempPath);
    }
});

test('users without analytics access can not view stock movements', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();
    $implementer->switchCurrentBusiness($business);

    $this->actingAs($implementer)
        ->get(route('backoffice.stock-movements.index'))
        ->assertForbidden();

    $this->actingAs($implementer)
        ->get(route('backoffice.stock-movements.export'))
        ->assertForbidden();
});

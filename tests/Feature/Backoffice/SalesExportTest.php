<?php

use App\Models\Business;
use App\Models\SyncReceivedEvent;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;

function createAppliedSyncEvent(Business $business, string $entityType, string $entityId, array $payload, int $minutesAgo = 0): SyncReceivedEvent
{
    return SyncReceivedEvent::query()->create([
        'business_id' => $business->id,
        'event_id' => 'evt-'.$entityType.'-'.$entityId.'-'.uniqid(),
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'operation' => 'upsert',
        'occurred_at' => now()->subMinutes($minutesAgo),
        'payload' => $payload,
        'status' => 'applied',
        'processed_at' => now()->subMinutes($minutesAgo),
    ]);
}

test('analytics user can export sales to an xlsx file with sales and product summary sheets', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();
    $business = Business::factory()->create(['default_currency' => 'CUP']);
    $superAdmin->switchCurrentBusiness($business);

    createAppliedSyncEvent($business, 'sales', 'sale-1', [
        'reference' => 'V-001',
        'status' => 'completed',
        'dateTime' => now()->subDay()->toIso8601String(),
        'total' => 300,
        'createdBy' => ['role' => 'seller', 'name' => 'Mariana'],
        'lines' => [
            ['productTitle' => 'Café molido', 'amount' => 2, 'price' => 100, 'subTotal' => 200],
            ['productTitle' => 'Azúcar', 'amount' => 1, 'price' => 100, 'subTotal' => 100],
        ],
    ], minutesAgo: 60);

    createAppliedSyncEvent($business, 'sales', 'sale-2', [
        'reference' => 'V-002',
        'status' => 'completed',
        'dateTime' => now()->subHours(2)->toIso8601String(),
        'total' => 250,
        'createdBy' => ['role' => 'owner', 'name' => 'Pedro'],
        'lines' => [
            ['productTitle' => 'Café molido', 'amount' => 1, 'price' => 100, 'subTotal' => 100],
            ['productTitle' => 'Pan', 'amount' => 3, 'price' => 50, 'subTotal' => 150],
        ],
    ], minutesAgo: 30);

    // Una venta devuelta no debe contar en el resumen por producto.
    createAppliedSyncEvent($business, 'sales', 'sale-3', [
        'reference' => 'V-003',
        'status' => 'returned',
        'dateTime' => now()->subHour()->toIso8601String(),
        'total' => 500,
        'createdBy' => ['role' => 'seller', 'name' => 'Mariana'],
        'lines' => [
            ['productTitle' => 'Café molido', 'amount' => 5, 'price' => 100, 'subTotal' => 500],
        ],
    ], minutesAgo: 10);

    $response = $this->actingAs($superAdmin)->get(route('backoffice.sales.export'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $binary = $response->streamedContent();
    expect($binary)->not->toBeEmpty();

    $tempPath = tempnam(sys_get_temp_dir(), 'sales-export-').'.xlsx';
    file_put_contents($tempPath, $binary);

    try {
        $spreadsheet = IOFactory::load($tempPath);

        expect($spreadsheet->getSheetNames())->toBe(['Ventas', 'Resumen por producto']);

        $salesSheet = $spreadsheet->getSheetByName('Ventas');
        $salesRows = $salesSheet->toArray();
        expect($salesRows[0])->toBe(['Referencia', 'Fecha', 'Estado', 'Cliente', 'Registrado por', 'Líneas', 'Productos', 'Total']);

        $references = collect(array_slice($salesRows, 1))->pluck(0)->all();
        expect($references)->toContain('V-001', 'V-002', 'V-003');

        $summarySheet = $spreadsheet->getSheetByName('Resumen por producto');
        $summaryRows = $summarySheet->toArray();
        expect($summaryRows[0])->toBe(['Producto', 'Cantidad vendida', 'Importe total']);

        $summary = collect(array_slice($summaryRows, 1))
            ->mapWithKeys(fn (array $row): array => [(string) $row[0] => ['quantity' => (float) $row[1], 'total' => (float) $row[2]]])
            ->all();

        // Only completed sales contribute: 2 + 1 = 3 of Café molido (returned sale-3 excluded).
        expect($summary['Café molido']['quantity'])->toBe(3.0);
        expect($summary['Café molido']['total'])->toBe(300.0);
        expect($summary['Azúcar']['quantity'])->toBe(1.0);
        expect($summary['Azúcar']['total'])->toBe(100.0);
        expect($summary['Pan']['quantity'])->toBe(3.0);
        expect($summary['Pan']['total'])->toBe(150.0);
    } finally {
        @unlink($tempPath);
    }
});

test('users without analytics access can not export sales', function () {
    $implementer = User::factory()->backofficeImplementer()->create();
    $business = Business::factory()->create();
    $implementer->switchCurrentBusiness($business);

    $this->actingAs($implementer)
        ->get(route('backoffice.sales.export'))
        ->assertForbidden();
});

test('export honors the status filter when produced', function () {
    $superAdmin = User::factory()->backofficeSuperAdmin()->create();
    $business = Business::factory()->create();
    $superAdmin->switchCurrentBusiness($business);

    createAppliedSyncEvent($business, 'sales', 'sale-completed', [
        'reference' => 'V-100',
        'status' => 'completed',
        'dateTime' => now()->toIso8601String(),
        'total' => 50,
        'lines' => [['productTitle' => 'A', 'amount' => 1, 'price' => 50, 'subTotal' => 50]],
    ]);

    createAppliedSyncEvent($business, 'sales', 'sale-returned', [
        'reference' => 'V-200',
        'status' => 'returned',
        'dateTime' => now()->toIso8601String(),
        'total' => 80,
        'lines' => [['productTitle' => 'B', 'amount' => 1, 'price' => 80, 'subTotal' => 80]],
    ]);

    $response = $this->actingAs($superAdmin)
        ->get(route('backoffice.sales.export', ['status' => 'completed']));

    $response->assertOk();

    $tempPath = tempnam(sys_get_temp_dir(), 'sales-export-').'.xlsx';
    file_put_contents($tempPath, $response->streamedContent());

    try {
        $spreadsheet = IOFactory::load($tempPath);
        $salesRows = $spreadsheet->getSheetByName('Ventas')->toArray();
        $references = collect(array_slice($salesRows, 1))->pluck(0)->all();

        expect($references)->toContain('V-100');
        expect($references)->not->toContain('V-200');
    } finally {
        @unlink($tempPath);
    }
});

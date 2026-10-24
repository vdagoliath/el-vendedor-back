<?php

namespace App\Support\Backoffice;

use App\Models\Business;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class InventoryExcelExporter
{
    /**
     * Build an xlsx file with global inventory and per-warehouse breakdown.
     *
     * @param  array<int, array{external_id: string, name: string}>  $warehouses
     * @param  array<int, array<string, mixed>>  $rows
     * @return resource
     */
    public function buildStream(Business $business, array $warehouses, array $rows)
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('Inventario '.$business->name)
            ->setCreator('El Vendedor');

        $this->fillGlobalSheet($spreadsheet->getActiveSheet(), $warehouses, $rows);
        $this->fillPerWarehouseSheet($spreadsheet->createSheet(), $warehouses, $rows);

        $spreadsheet->setActiveSheetIndex(0);

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Unable to allocate temporary stream for the xlsx file.');
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($stream);
        rewind($stream);

        return $stream;
    }

    /**
     * @param  array<int, array{external_id: string, name: string}>  $warehouses
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fillGlobalSheet(Worksheet $sheet, array $warehouses, array $rows): void
    {
        $sheet->setTitle('Inventario global');

        $headers = ['Producto', 'Código'];
        foreach ($warehouses as $warehouse) {
            $headers[] = $warehouse['name'];
        }
        $headers[] = 'Total';
        $headers[] = 'Stock mínimo';

        $sheet->fromArray($headers, null, 'A1');
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);

        $row = 2;
        foreach ($rows as $entry) {
            $columnIndex = 1;
            $sheet->setCellValue([$columnIndex++, $row], (string) $entry['product_title']);
            $sheet->setCellValue([$columnIndex++, $row], (string) ($entry['product_code'] ?? ''));

            foreach ($warehouses as $warehouse) {
                $qty = (float) ($entry['by_warehouse'][$warehouse['external_id']] ?? 0);
                $sheet->setCellValue([$columnIndex++, $row], $qty);
            }

            $sheet->setCellValue([$columnIndex++, $row], (float) ($entry['total'] ?? 0));
            $sheet->setCellValue([$columnIndex, $row], $entry['min_stock'] !== null ? (float) $entry['min_stock'] : '');

            $row++;
        }

        for ($i = 1, $iMax = count($headers); $i <= $iMax; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }

    /**
     * @param  array<int, array{external_id: string, name: string}>  $warehouses
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fillPerWarehouseSheet(Worksheet $sheet, array $warehouses, array $rows): void
    {
        $sheet->setTitle('Por almacén');

        $headers = ['Almacén', 'Producto', 'Código', 'Cantidad'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);

        $row = 2;
        $warehousesByExternalId = collect($warehouses)->keyBy('external_id');

        foreach ($rows as $entry) {
            foreach ($entry['by_warehouse'] ?? [] as $warehouseId => $qty) {
                $qty = (float) $qty;
                if ($qty === 0.0) {
                    continue;
                }

                $warehouseName = $warehousesByExternalId[$warehouseId]['name'] ?? 'Desconocido';

                $sheet->setCellValue('A'.$row, (string) $warehouseName);
                $sheet->setCellValue('B'.$row, (string) $entry['product_title']);
                $sheet->setCellValue('C'.$row, (string) ($entry['product_code'] ?? ''));
                $sheet->setCellValue('D'.$row, $qty);

                $row++;
            }
        }

        foreach (['A', 'B', 'C', 'D'] as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
}

<?php

namespace App\Support\Backoffice;

use App\Models\Business;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class StockAdjustmentsExcelExporter
{
    /**
     * Build an xlsx file with stock adjustments.
     *
     * @param  array<int, array<string, mixed>>  $adjustments
     * @return resource
     */
    public function buildStream(Business $business, array $adjustments)
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('Ajustes de inventario '.$business->name)
            ->setCreator('El Vendedor');

        $this->fillAdjustmentsSheet($spreadsheet->getActiveSheet(), $adjustments);
        $this->fillSummarySheet($spreadsheet->createSheet(), $adjustments);

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
     * @param  array<int, array<string, mixed>>  $adjustments
     */
    private function fillAdjustmentsSheet(Worksheet $sheet, array $adjustments): void
    {
        $sheet->setTitle('Ajustes');

        $headers = [
            'Fecha',
            'Producto',
            'Código',
            'Almacén',
            'Cantidad previa',
            'Cantidad objetivo',
            'Diferencia',
            'Razón',
        ];

        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);

        $row = 2;
        foreach ($adjustments as $adjustment) {
            $sheet->setCellValue('A'.$row, $this->formatDateTime($adjustment['adjustment_at'] ?? null));
            $sheet->setCellValue('B'.$row, (string) ($adjustment['product']['title'] ?? ''));
            $sheet->setCellValue('C'.$row, (string) ($adjustment['product']['code'] ?? ''));
            $sheet->setCellValue('D'.$row, (string) ($adjustment['warehouse']['name'] ?? ''));
            $sheet->setCellValue('E'.$row, $adjustment['previous_quantity'] !== null ? (float) $adjustment['previous_quantity'] : '');
            $sheet->setCellValue('F'.$row, (float) ($adjustment['target_quantity'] ?? 0));
            $sheet->setCellValue('G'.$row, (float) ($adjustment['change_quantity'] ?? 0));
            $sheet->setCellValue('H'.$row, (string) ($adjustment['reason'] ?? ''));

            $row++;
        }

        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $adjustments
     */
    private function fillSummarySheet(Worksheet $sheet, array $adjustments): void
    {
        $sheet->setTitle('Resumen por producto');

        $headers = ['Producto', 'Almacén', 'Ajustes', 'Diferencia total'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);

        $rows = $this->aggregateByProductWarehouse($adjustments);

        $row = 2;
        foreach ($rows as $item) {
            $sheet->setCellValue('A'.$row, $item['product']);
            $sheet->setCellValue('B'.$row, $item['warehouse']);
            $sheet->setCellValue('C'.$row, $item['count']);
            $sheet->setCellValue('D'.$row, $item['change']);

            $row++;
        }

        foreach (['A', 'B', 'C', 'D'] as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $adjustments
     * @return array<int, array{product: string, warehouse: string, count: int, change: float}>
     */
    private function aggregateByProductWarehouse(array $adjustments): array
    {
        $grouped = [];

        foreach ($adjustments as $adjustment) {
            $product = (string) ($adjustment['product']['title'] ?? 'Desconocido');
            $warehouse = (string) ($adjustment['warehouse']['name'] ?? 'Desconocido');
            $key = $product.'|'.$warehouse;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'product' => $product,
                    'warehouse' => $warehouse,
                    'count' => 0,
                    'change' => 0.0,
                ];
            }

            $grouped[$key]['count']++;
            $grouped[$key]['change'] += (float) ($adjustment['change_quantity'] ?? 0);
        }

        $rows = array_values($grouped);
        usort($rows, fn (array $a, array $b): int => abs($b['change']) <=> abs($a['change']));

        return $rows;
    }

    private function formatDateTime(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i');
        } catch (\Throwable) {
            return '';
        }
    }
}

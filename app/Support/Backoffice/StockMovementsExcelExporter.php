<?php

namespace App\Support\Backoffice;

use App\Models\Business;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class StockMovementsExcelExporter
{
    /**
     * Build an xlsx file with stock movements between warehouses.
     *
     * @param  array<int, array<string, mixed>>  $movements
     * @return resource
     */
    public function buildStream(Business $business, array $movements)
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('Movimientos de almacén '.$business->name)
            ->setCreator('El Vendedor');

        $this->fillMovementsSheet($spreadsheet->getActiveSheet(), $movements);
        $this->fillSummarySheet($spreadsheet->createSheet(), $movements);

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
     * @param  array<int, array<string, mixed>>  $movements
     */
    private function fillMovementsSheet(Worksheet $sheet, array $movements): void
    {
        $sheet->setTitle('Movimientos');

        $headers = [
            'Fecha',
            'Producto',
            'Código',
            'Origen',
            'Destino',
            'Cantidad',
        ];

        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        $row = 2;
        foreach ($movements as $movement) {
            $sheet->setCellValue('A'.$row, $this->formatDateTime($movement['movement_at'] ?? null));
            $sheet->setCellValue('B'.$row, (string) ($movement['product']['title'] ?? ''));
            $sheet->setCellValue('C'.$row, (string) ($movement['product']['code'] ?? ''));
            $sheet->setCellValue('D'.$row, (string) ($movement['from_warehouse']['name'] ?? ''));
            $sheet->setCellValue('E'.$row, (string) ($movement['to_warehouse']['name'] ?? ''));
            $sheet->setCellValue('F'.$row, (float) ($movement['quantity'] ?? 0));

            $row++;
        }

        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $movements
     */
    private function fillSummarySheet(Worksheet $sheet, array $movements): void
    {
        $sheet->setTitle('Resumen por ruta');

        $headers = ['Origen', 'Destino', 'Movimientos', 'Cantidad total'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);

        $rows = $this->aggregateByRoute($movements);

        $row = 2;
        foreach ($rows as $item) {
            $sheet->setCellValue('A'.$row, $item['from']);
            $sheet->setCellValue('B'.$row, $item['to']);
            $sheet->setCellValue('C'.$row, $item['count']);
            $sheet->setCellValue('D'.$row, $item['quantity']);

            $row++;
        }

        foreach (['A', 'B', 'C', 'D'] as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $movements
     * @return array<int, array{from: string, to: string, count: int, quantity: float}>
     */
    private function aggregateByRoute(array $movements): array
    {
        $grouped = [];

        foreach ($movements as $movement) {
            $from = (string) ($movement['from_warehouse']['name'] ?? 'Desconocido');
            $to = (string) ($movement['to_warehouse']['name'] ?? 'Desconocido');
            $key = $from.'|'.$to;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'from' => $from,
                    'to' => $to,
                    'count' => 0,
                    'quantity' => 0.0,
                ];
            }

            $grouped[$key]['count']++;
            $grouped[$key]['quantity'] += (float) ($movement['quantity'] ?? 0);
        }

        $rows = array_values($grouped);
        usort($rows, fn (array $a, array $b): int => $b['quantity'] <=> $a['quantity']);

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

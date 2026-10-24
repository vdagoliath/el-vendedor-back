<?php

namespace App\Support\Backoffice;

use App\Models\Business;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductLossesExcelExporter
{
    private const LOSS_TYPE_LABELS = [
        'damaged' => 'Dañado',
        'expired' => 'Vencido',
        'stolen' => 'Robo',
        'other' => 'Otro',
    ];

    /**
     * Build an xlsx file with product losses.
     *
     * @param  array<int, array<string, mixed>>  $losses
     * @return resource
     */
    public function buildStream(Business $business, array $losses)
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('Mermas '.$business->name)
            ->setCreator('El Vendedor');

        $this->fillLossesSheet($spreadsheet->getActiveSheet(), $losses);
        $this->fillSummarySheet($spreadsheet->createSheet(), $losses);

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
     * @param  array<int, array<string, mixed>>  $losses
     */
    private function fillLossesSheet(Worksheet $sheet, array $losses): void
    {
        $sheet->setTitle('Mermas');

        $headers = [
            'Fecha',
            'Producto',
            'Código',
            'Almacén',
            'Tipo',
            'Cantidad',
            'Cantidad previa',
            'Costo unitario',
            'Costo total',
            'Notas',
        ];

        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:J1')->getFont()->setBold(true);

        $row = 2;
        foreach ($losses as $loss) {
            $sheet->setCellValue('A'.$row, $this->formatDateTime($loss['loss_at'] ?? null));
            $sheet->setCellValue('B'.$row, (string) ($loss['product']['title'] ?? ''));
            $sheet->setCellValue('C'.$row, (string) ($loss['product']['code'] ?? ''));
            $sheet->setCellValue('D'.$row, (string) ($loss['warehouse']['name'] ?? ''));
            $sheet->setCellValue('E'.$row, $this->lossTypeLabel((string) ($loss['loss_type'] ?? 'other')));
            $sheet->setCellValue('F'.$row, (float) ($loss['quantity'] ?? 0));
            $sheet->setCellValue('G'.$row, $loss['previous_quantity'] !== null ? (float) $loss['previous_quantity'] : '');
            $sheet->setCellValue('H'.$row, $loss['unit_cost'] !== null ? (float) $loss['unit_cost'] : '');
            $sheet->setCellValue('I'.$row, $loss['total_cost'] !== null ? (float) $loss['total_cost'] : '');
            $sheet->setCellValue('J'.$row, (string) ($loss['notes'] ?? ''));

            $row++;
        }

        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $losses
     */
    private function fillSummarySheet(Worksheet $sheet, array $losses): void
    {
        $sheet->setTitle('Resumen por producto');

        $headers = ['Producto', 'Almacén', 'Mermas', 'Cantidad total', 'Costo total'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);

        $rows = $this->aggregateByProductWarehouse($losses);

        $row = 2;
        foreach ($rows as $item) {
            $sheet->setCellValue('A'.$row, $item['product']);
            $sheet->setCellValue('B'.$row, $item['warehouse']);
            $sheet->setCellValue('C'.$row, $item['count']);
            $sheet->setCellValue('D'.$row, $item['quantity']);
            $sheet->setCellValue('E'.$row, $item['cost']);

            $row++;
        }

        foreach (['A', 'B', 'C', 'D', 'E'] as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $losses
     * @return array<int, array{product: string, warehouse: string, count: int, quantity: float, cost: float}>
     */
    private function aggregateByProductWarehouse(array $losses): array
    {
        $grouped = [];

        foreach ($losses as $loss) {
            $product = (string) ($loss['product']['title'] ?? 'Desconocido');
            $warehouse = (string) ($loss['warehouse']['name'] ?? 'Desconocido');
            $key = $product.'|'.$warehouse;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'product' => $product,
                    'warehouse' => $warehouse,
                    'count' => 0,
                    'quantity' => 0.0,
                    'cost' => 0.0,
                ];
            }

            $grouped[$key]['count']++;
            $grouped[$key]['quantity'] += (float) ($loss['quantity'] ?? 0);
            $grouped[$key]['cost'] += (float) ($loss['total_cost'] ?? 0);
        }

        $rows = array_values($grouped);
        usort($rows, fn (array $a, array $b): int => $b['quantity'] <=> $a['quantity']);

        return $rows;
    }

    private function lossTypeLabel(string $type): string
    {
        return self::LOSS_TYPE_LABELS[$type] ?? $type;
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

<?php

namespace App\Support\Backoffice;

use App\Models\Business;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SalesExcelExporter
{
    /**
     * Build an xlsx file with two sheets: detailed sales and per-product summary.
     *
     * @param  array<int, array<string, mixed>>  $sales
     * @return resource
     */
    public function buildStream(Business $business, array $sales)
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('Ventas '.$business->name)
            ->setCreator('El Vendedor');

        $currency = (string) ($business->default_currency ?? 'CUP');
        $moneyFormat = $this->currencyFormat($currency);

        $this->fillSalesSheet($spreadsheet->getActiveSheet(), $sales, $moneyFormat);

        $summarySheet = $spreadsheet->createSheet();
        $this->fillSummarySheet($summarySheet, $sales, $moneyFormat);

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
     * @param  array<int, array<string, mixed>>  $sales
     */
    private function fillSalesSheet(Worksheet $sheet, array $sales, string $moneyFormat): void
    {
        $sheet->setTitle('Ventas');

        $headers = [
            'Referencia',
            'Fecha',
            'Estado',
            'Cliente',
            'Registrado por',
            'Líneas',
            'Productos',
            'Total',
        ];

        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);

        $row = 2;
        foreach ($sales as $sale) {
            $sheet->setCellValue('A'.$row, (string) ($sale['reference'] ?? ''));
            $sheet->setCellValue('B'.$row, $this->formatDateTime($sale['date_time'] ?? null));
            $sheet->setCellValue('C'.$row, $this->translateStatus((string) ($sale['status'] ?? 'pending')));
            $sheet->setCellValue('D'.$row, (string) ($sale['customer_name'] ?? ''));
            $sheet->setCellValue('E'.$row, (string) ($sale['created_by']['name'] ?? ''));
            $sheet->setCellValue('F'.$row, (int) ($sale['items_count'] ?? 0));
            $sheet->setCellValue('G'.$row, $this->summarizeLines($sale['lines'] ?? []));
            $sheet->setCellValue('H'.$row, (float) ($sale['total'] ?? 0));
            $sheet->getStyle('H'.$row)->getNumberFormat()->setFormatCode($moneyFormat);

            $row++;
        }

        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $sales
     */
    private function fillSummarySheet(Worksheet $sheet, array $sales, string $moneyFormat): void
    {
        $sheet->setTitle('Resumen por producto');

        $headers = ['Producto', 'Cantidad vendida', 'Importe total'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);

        $summary = $this->aggregateByProduct($sales);

        $row = 2;
        foreach ($summary as $line) {
            $sheet->setCellValue('A'.$row, $line['product_title']);
            $sheet->setCellValue('B'.$row, $line['quantity']);
            $sheet->setCellValue('C'.$row, $line['total']);
            $sheet->getStyle('C'.$row)->getNumberFormat()->setFormatCode($moneyFormat);

            $row++;
        }

        foreach (['A', 'B', 'C'] as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $sales
     * @return array<int, array{product_title: string, quantity: float, total: float}>
     */
    private function aggregateByProduct(array $sales): array
    {
        $grouped = [];

        foreach ($sales as $sale) {
            $status = (string) ($sale['status'] ?? 'pending');
            if ($status === 'returned' || $status === 'canceled') {
                continue;
            }

            foreach ($sale['lines'] ?? [] as $line) {
                $title = trim((string) ($line['product_title'] ?? 'Producto sin nombre')) ?: 'Producto sin nombre';
                $quantity = (float) ($line['quantity'] ?? 0);
                $subtotal = (float) ($line['subtotal'] ?? (((float) ($line['price'] ?? 0)) * $quantity));

                if (! isset($grouped[$title])) {
                    $grouped[$title] = [
                        'product_title' => $title,
                        'quantity' => 0.0,
                        'total' => 0.0,
                    ];
                }

                $grouped[$title]['quantity'] += $quantity;
                $grouped[$title]['total'] += $subtotal;
            }
        }

        $rows = array_values($grouped);
        usort($rows, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function summarizeLines(array $lines): string
    {
        return collect($lines)
            ->map(function (array $line): string {
                $title = (string) ($line['product_title'] ?? 'Producto sin nombre');
                $quantity = (float) ($line['quantity'] ?? 0);

                return $this->formatNumber($quantity).' x '.$title;
            })
            ->implode("\n");
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

    private function translateStatus(string $status): string
    {
        return match ($status) {
            'completed' => 'Completada',
            'returned' => 'Devuelta',
            'canceled' => 'Cancelada',
            'pending' => 'Pendiente',
            'credit' => 'A crédito',
            default => ucfirst($status),
        };
    }

    private function currencyFormat(string $currency): string
    {
        $safe = preg_replace('/[^A-Za-z]/', '', $currency) ?: 'CUP';

        return '#,##0.00 "'.$safe.'"';
    }

    private function formatNumber(float $value): string
    {
        if (floor($value) === $value) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}

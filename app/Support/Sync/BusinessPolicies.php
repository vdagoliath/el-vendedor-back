<?php

namespace App\Support\Sync;

final class BusinessPolicies
{
    /**
     * Normalize policy keys and scalar types shared with mobile sync.
     *
     * @param  array<string, mixed>|null  $policies
     * @return array<string, mixed>
     */
    public static function normalize(?array $policies): array
    {
        if (! is_array($policies)) {
            return [];
        }

        $normalized = $policies;

        if (! array_key_exists('productCodePrefix', $normalized) && array_key_exists('product_code_prefix', $normalized)) {
            $normalized['productCodePrefix'] = $normalized['product_code_prefix'];
        }

        if (! array_key_exists('productCodeDigits', $normalized) && array_key_exists('product_code_digits', $normalized)) {
            $normalized['productCodeDigits'] = $normalized['product_code_digits'];
        }

        unset($normalized['product_code_prefix'], $normalized['product_code_digits']);

        if (array_key_exists('productCodePrefix', $normalized)) {
            $normalized['productCodePrefix'] = trim((string) $normalized['productCodePrefix']);
        }

        if (array_key_exists('productCodeDigits', $normalized)) {
            $normalized['productCodeDigits'] = self::nonNegativeInteger($normalized['productCodeDigits']);
        }

        if (array_key_exists('sellerOperationRetentionMonths', $normalized)) {
            $normalized['sellerOperationRetentionMonths'] = max(1, self::nonNegativeInteger($normalized['sellerOperationRetentionMonths']));
        }

        foreach ([
            'allowZeroStockSales',
            'enableDebtManagement',
            'showPrice',
            'showStock',
            'showQrCodeGenerator',
            'printSaleReceiptEnabled',
            'chooseBatchOnStockOut',
            'enableSellerOperationSanitization',
        ] as $key) {
            if (array_key_exists($key, $normalized)) {
                $normalized[$key] = self::boolean($normalized[$key]);
            }
        }

        return $normalized;
    }

    private static function nonNegativeInteger(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, (int) floor((float) $value));
        }

        return 0;
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $filtered ?? (bool) $value;
    }
}

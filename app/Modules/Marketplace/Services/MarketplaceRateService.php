<?php

namespace App\Modules\Marketplace\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tasas de VISUALIZACION (solo display). USD->CUP desde QvaPay P2P averages;
 * USD->CAD/RUB/MXN/UYU desde open.er-api. La conversion definitiva (cotizacion)
 * nunca usa estas tasas.
 */
final class MarketplaceRateService
{
    private const CACHE_KEY = 'marketplace.rates.v1';

    private const TTL_SECONDS = 300;

    private const STANDARD_CODES = ['CAD', 'RUB', 'MXN', 'UYU'];

    /**
     * Convierte un monto entre monedas usando USD como pivote.
     * Devuelve null cuando falta alguna de las tasas.
     */
    public function convert(float $amount, string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return round($amount, 2);
        }

        $rates = $this->rates()['rates'] ?? [];
        $fromRate = $from === 'USD' ? 1.0 : ($rates[$from] ?? null);
        $toRate = $to === 'USD' ? 1.0 : ($rates[$to] ?? null);

        if (! is_numeric($fromRate) || ! is_numeric($toRate) || (float) $fromRate <= 0 || (float) $toRate <= 0) {
            return null;
        }

        return round(($amount / (float) $fromRate) * (float) $toRate, 2);
    }

    /**
     * Invalida la cache para forzar una lectura fresca de los proveedores.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{
     *     base: string,
     *     updated_at: string,
     *     rates: array<string, int|float>,
     *     cup_source_updated_at: string|null,
     *     error?: string|null,
     * }
     */
    public function rates(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, function (): array {
            $rates = ['USD' => 1];

            $cupSourceUpdatedAt = null;
            $error = null;

            try {
                $qvapay = Http::timeout(6)->get(config('services.qvapay.p2p_averages_url'))
                    ->throw()
                    ->json();
                $cupAverage = data_get($qvapay, 'BANK_CUP.average');

                if (is_numeric($cupAverage) && (float) $cupAverage > 0) {
                    $rates['CUP'] = (float) $cupAverage;
                    $cupSourceUpdatedAt = data_get($qvapay, 'BANK_CUP.updated_at');
                } else {
                    $error = 'QvaPay no incluyó la tasa BANK_CUP.';
                }
            } catch (Throwable) {
                $error = 'QvaPay no disponible.';
            }

            try {
                $openEr = Http::timeout(6)->get(config('services.er_api.latest_url'))
                    ->throw()
                    ->json();

                foreach (self::STANDARD_CODES as $code) {
                    $rate = data_get($openEr, "rates.$code");
                    if (is_numeric($rate) && (float) $rate > 0) {
                        $rates[$code] = (float) $rate;
                    }
                }
            } catch (Throwable) {
                $error = $error ? "{$error} open.er-api no disponible." : 'open.er-api no disponible.';
            }

            return [
                'base' => 'USD',
                'updated_at' => now()->toISOString(),
                'rates' => $rates,
                'cup_source_updated_at' => $cupSourceUpdatedAt,
                'error' => $error,
            ];
        });
    }
}

<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Services\MarketplaceRateService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceRateController extends Controller
{
    public function __construct(private readonly MarketplaceRateService $rates) {}

    /**
     * Tasas de visualizacion actuales (USD -> CUP/QvaPay y resto/open.er-api).
     */
    public function index(): Response
    {
        return Inertia::render('backoffice/MarketplaceRates', [
            'rates' => $this->rates->rates(),
            'providers' => [
                'qvapay' => (string) config('services.qvapay.p2p_averages_url'),
                'open_er_api' => (string) config('services.er_api.latest_url'),
            ],
        ]);
    }

    /**
     * Fuerza una lectura fresca de los proveedores (invalida cache).
     */
    public function refresh(): RedirectResponse
    {
        $this->rates->forget();

        return to_route('backoffice.marketplace.rates.index')
            ->with('success', 'Tasas actualizadas desde los proveedores.');
    }
}

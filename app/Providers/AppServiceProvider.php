<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Modules\Inventory\Contracts\InventoryAvailabilityService;
use App\Modules\Inventory\Contracts\InventoryReservationService;
use App\Modules\Inventory\Services\EloquentInventoryReservationService;
use App\Modules\Inventory\Services\StockProjectionInventoryAvailabilityService;
use App\Modules\Marketplace\Contracts\MarketplaceEngineInterface;
use App\Modules\Marketplace\Engine\HeuristicMarketplaceEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(InventoryAvailabilityService::class, StockProjectionInventoryAvailabilityService::class);
        $this->app->bind(InventoryReservationService::class, EloquentInventoryReservationService::class);
        $this->app->bind(MarketplaceEngineInterface::class, HeuristicMarketplaceEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(8));
    }
}

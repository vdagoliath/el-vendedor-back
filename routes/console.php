<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Archivar eventos de sync ya aplicados mayores a 180 días cada domingo a las 3am.
Schedule::command('sync:prune-events --days=180')
    ->weeklyOn(0, '03:00')
    ->onOneServer()
    ->withoutOverlapping();

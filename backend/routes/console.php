<?php

use App\Console\Commands\ImportMasterData;
use App\Jobs\GeneratePmTasks;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new GeneratePmTasks)->daily();

/** Nightly, before the midnight PM Task generation, so it sees fresh master data (issue 03). */
Schedule::command(ImportMasterData::class)->dailyAt('23:30')->withoutOverlapping();

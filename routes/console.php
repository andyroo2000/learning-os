<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('study:prune-import-archives')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('content:recover-generation-requests')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5);

Schedule::command('content:prune-generation-requests')
    ->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping(60);

<?php

use App\Domain\Calendar\Actions\DispatchGoogleCalendarSyncsAction;
use App\Domain\Calendar\Actions\PruneExpiredGoogleCalendarConnectIntentsAction;
use App\Domain\Japanese\Actions\RunDailyWaniKaniTransferBridgeAction;
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

Schedule::call(fn () => app(PruneExpiredGoogleCalendarConnectIntentsAction::class)->handle())
    ->name('calendar:prune-connect-intents')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::call(fn () => app(DispatchGoogleCalendarSyncsAction::class)->handle())
    ->name('calendar:sync-connections')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(15);

Schedule::call(fn () => app(RunDailyWaniKaniTransferBridgeAction::class)->handle())
    ->name('wanikani:daily-transfer-bridge')
    ->dailyAt('08:15')
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

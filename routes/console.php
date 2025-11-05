<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('website:crawl --queue --limit=100')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('email:process --queue --batch-size=50')
    ->hourly()
    ->between('8:00', '17:00')
    ->weekdays()
    ->withoutOverlapping();

Schedule::command('email:process --approved --queue --batch-size=10')
    ->everyThirtyMinutes()
    ->between('8:00', '17:00')
    ->weekdays()
    ->withoutOverlapping();

Schedule::call(function () {
    \App\Models\SmtpCredential::query()->update([
        'emails_sent_today' => 0,
        'last_reset_date' => now()->toDateString(),
    ]);
})->daily();

Schedule::call(function () {
    app(\App\Services\ReviewQueueService::class)->cleanupOldEntries(90);
})->weekly()->sundays()->at('02:00');

Schedule::command('system:stats --json')
    ->daily()
    ->appendOutputTo(storage_path('logs/stats.log'));

Schedule::command('cache:clear')
    ->daily()
    ->at('03:00');

Schedule::command('queue:prune-batches --hours=48')
    ->daily()
    ->at('04:00');

Schedule::command('queue:prune-failed --hours=168')
    ->weekly()
    ->sundays()
    ->at('03:00');

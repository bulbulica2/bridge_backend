<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
  $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// free the seats of players who closed the tab or lost the connection
// (run locally with `php artisan schedule:work`)
Schedule::command('tables:release-idle-seats')->everyMinute()->withoutOverlapping();

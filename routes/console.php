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

// mid-set, mark players who went quiet as away, and hand to robots the seats
// of those away past their reservation and of the player on turn once their
// turn runs out: every ten seconds, since a minute is too coarse for a
// one-minute turn
// (schedule:work runs sub-minute tasks)
Schedule::command('tables:check-away')->everyTenSeconds()->withoutOverlapping();

// delete tables only robots have kept since their last human left
Schedule::command('tables:delete-unattended')->everyMinute()->withoutOverlapping();

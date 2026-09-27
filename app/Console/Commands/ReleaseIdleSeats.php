<?php

namespace App\Console\Commands;

use App\Services\TableSeatService;
use Illuminate\Console\Command;

class ReleaseIdleSeats extends Command
{
  protected $signature = 'tables:release-idle-seats';

  protected $description = 'Free the seats of players who have gone quiet (closed the tab, lost the connection)';

  public function handle(TableSeatService $seats): int
  {
    $freed = $seats->releaseIdleSeats();

    $this->info("Freed $freed idle ".($freed === 1 ? 'seat' : 'seats').'.');

    return self::SUCCESS;
  }
}

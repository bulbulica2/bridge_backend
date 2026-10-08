<?php

namespace App\Console\Commands;

use App\Services\KibitzerService;
use App\Services\TableSeatService;
use Illuminate\Console\Command;

class ReleaseIdleSeats extends Command
{
  protected $signature = 'tables:release-idle-seats';

  protected $description = 'Free the seats of players, and drop the kibitzers, who have gone quiet (closed the tab, lost the connection)';

  public function handle(TableSeatService $seats, KibitzerService $kibitzers): int
  {
    $freed = $seats->releaseIdleSeats();
    $dropped = $kibitzers->releaseIdle();

    $this->info("Freed $freed idle ".($freed === 1 ? 'seat' : 'seats').'.');
    $this->info("Dropped $dropped idle ".($dropped === 1 ? 'kibitzer' : 'kibitzers').'.');

    return self::SUCCESS;
  }
}

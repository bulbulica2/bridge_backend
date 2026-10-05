<?php

namespace App\Console\Commands;

use App\Services\TableSeatService;
use Illuminate\Console\Command;

class CheckAwayPlayers extends Command
{
  protected $signature = 'tables:check-away';

  protected $description = 'Mark players who went quiet mid-set as away, and hand the seat of the player on turn to a robot once their turn clock runs out';

  public function handle(TableSeatService $seats): int
  {
    ['away' => $away, 'timed_out' => $timedOut, 'freed' => $freed] = $seats->checkAway();

    $this->info(
      "Marked $away ".($away === 1 ? 'player' : 'players').' away, '
      ."replaced $timedOut ".($timedOut === 1 ? 'player' : 'players').' with a robot, '
      ."freed $freed ".($freed === 1 ? 'seat' : 'seats').'.'
    );

    return self::SUCCESS;
  }
}

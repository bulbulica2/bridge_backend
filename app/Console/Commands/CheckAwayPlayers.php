<?php

namespace App\Console\Commands;

use App\Services\ClaimService;
use App\Services\TableSeatService;
use Illuminate\Console\Command;

class CheckAwayPlayers extends Command
{
  protected $signature = 'tables:check-away';

  protected $description = 'Mark players who went quiet mid-set as away, hand the seat of the player on turn to a robot once their turn clock runs out, and expire claims nobody answered in time';

  public function handle(TableSeatService $seats, ClaimService $claims): int
  {
    // first, so a table freed of its claim gets its turn clock back
    // (`turn_started_at`) instead of being checked as still claimed
    $expired = $claims->expireAllOverdue();

    ['away' => $away, 'timed_out' => $timedOut, 'freed' => $freed] = $seats->checkAway();

    $this->info(
      "Marked $away ".($away === 1 ? 'player' : 'players').' away, '
      ."replaced $timedOut ".($timedOut === 1 ? 'player' : 'players').' with a robot, '
      ."freed $freed ".($freed === 1 ? 'seat' : 'seats').', '
      ."expired $expired ".($expired === 1 ? 'claim' : 'claims').'.'
    );

    return self::SUCCESS;
  }
}

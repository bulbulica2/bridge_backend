<?php

namespace App\Console\Commands;

use App\Services\TableSeatService;
use Illuminate\Console\Command;

class CheckAwayPlayers extends Command
{
  protected $signature = 'tables:check-away';

  protected $description = 'Mark players who went quiet mid-set as away, and forfeit the set for the side of one away too long';

  public function handle(TableSeatService $seats): int
  {
    ['away' => $away, 'forfeited' => $forfeited, 'freed' => $freed] = $seats->checkAway();

    $this->info(
      "Marked $away ".($away === 1 ? 'player' : 'players').' away, '
      ."forfeited $forfeited ".($forfeited === 1 ? 'set' : 'sets').', '
      ."freed $freed ".($freed === 1 ? 'seat' : 'seats').'.'
    );

    return self::SUCCESS;
  }
}

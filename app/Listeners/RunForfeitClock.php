<?php

namespace App\Listeners;

use App\Events\PlayingUpdated;
use App\Services\TableSeatService;

/**
 * Moves the forfeit clock with the turn: after every `PlayingUpdated` (a
 * deal, a call, a card, a claim made, answered, withdrawn or expired; sent
 * once its transaction commits), an away player the board now waits for
 * gets their clock and one it no longer waits for loses it
 * (`TableSeatService::syncForfeitClock()`). Runs at once, in the process
 * that made the move, so the table hears of it straight after the move.
 */
class RunForfeitClock
{
  public function __construct(private TableSeatService $seats) {}

  public function handle(PlayingUpdated $event): void
  {
    $this->seats->syncForfeitClockAt($event->tableId);
  }
}

<?php

namespace App\Jobs;

use App\Services\BoardSelectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deals a set's next board once the finished one has been on show for
 * `bridge.next_board_seconds`. `BoardTable::finish()` queues it with that
 * delay; by then the table may have moved on already (every human asked
 * for the next board), the players may have changed or the set may be
 * over, and `BoardSelectionService::dealNext()` then deals nothing.
 */
class DealNextBoard implements ShouldQueue
{
  use Queueable;

  public function __construct(public int $playingId) {}

  public function handle(BoardSelectionService $boards): void
  {
    $boards->dealNext($this->playingId);
  }
}

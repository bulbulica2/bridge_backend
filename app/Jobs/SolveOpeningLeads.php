<?php

namespace App\Jobs;

use App\Models\Board;
use App\Services\DoubleDummyService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Solves the opening leads for one declarer and strain on a board and
 * stores them (`DoubleDummyService::solveLeads()`). Queued when a playing
 * with that contract finishes, and by a review that finds them missing;
 * one per contract waits in the queue at a time, and leads already stored
 * are not solved again.
 */
class SolveOpeningLeads implements ShouldBeUnique, ShouldQueue
{
  use Queueable;

  /**
   * Seconds the one-at-a-time lock outlives a job that never ran (a lost
   * queue), after which a review queues its leads again.
   */
  public int $uniqueFor = 600;

  public function __construct(public int $boardId, public string $declarer, public string $strain) {}

  public function uniqueId(): string
  {
    return "$this->boardId-$this->declarer-$this->strain";
  }

  public function handle(DoubleDummyService $doubleDummy): void
  {
    $board = Board::find($this->boardId);

    if ($board !== null) {
      $doubleDummy->solveLeads($board, $this->declarer, $this->strain);
    }
  }
}

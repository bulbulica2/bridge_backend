<?php

namespace App\Jobs;

use App\Models\Board;
use App\Services\DoubleDummyService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Solves a board's double dummy table and stores it
 * (`DoubleDummyService::solveTable()`). Queued when the board is dealt, and
 * by a read that finds it missing; one per board waits in the queue at a
 * time, and a table already stored is not solved again.
 */
class SolveDoubleDummyTable implements ShouldBeUnique, ShouldQueue
{
  use Queueable;

  /**
   * Seconds the one-at-a-time lock outlives a job that never ran (a lost
   * queue), after which a read queues the table again.
   */
  public int $uniqueFor = 600;

  public function __construct(public int $boardId) {}

  public function uniqueId(): string
  {
    return (string) $this->boardId;
  }

  public function handle(DoubleDummyService $doubleDummy): void
  {
    $board = Board::find($this->boardId);

    if ($board !== null) {
      $doubleDummy->solveTable($board);
    }
  }
}

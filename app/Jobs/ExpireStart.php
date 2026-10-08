<?php

namespace App\Jobs;

use App\Services\TableSeatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Frees a seat whose player hasn't pressed Start by its `start_deadline`.
 * `BoardSelectionService::syncStartDeadline()` queues it with that delay; by
 * then they may have pressed it, the timer may have stopped (somebody
 * withdrew, left or changed the settings) or started afresh, and
 * `TableSeatService::expireStart()` then frees nothing.
 */
class ExpireStart implements ShouldQueue
{
  use Queueable;

  public function __construct(public int $seatId) {}

  public function handle(TableSeatService $seats): void
  {
    $seats->expireStart($this->seatId);
  }
}

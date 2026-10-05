<?php

namespace App\Listeners;

use App\Services\QueueHealthService;
use Illuminate\Queue\Events\Looping;

/**
 * The worker's heartbeat: `queue:work` fires `Looping` before every look at
 * the queue (ten times a second with `--sleep=0.1` when it is idle), and
 * `QueueHealthService::beat()` writes one at most every ten seconds. Never
 * returns false, which would pause the worker.
 */
class BeatQueueHeartbeat
{
  public function __construct(private QueueHealthService $health) {}

  public function handle(Looping $event): void
  {
    $this->health->beat();
  }
}

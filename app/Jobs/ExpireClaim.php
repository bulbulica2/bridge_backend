<?php

namespace App\Jobs;

use App\Services\ClaimService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rejects a claim nobody finished answering in time. `ClaimService::claim()`
 * queues it with a delay up to the claim's `claim_expires_at`; by then the
 * claim may have been answered or withdrawn, or replaced by a newer one with
 * its own deadline, and `ClaimService::expire()` then leaves it alone.
 */
class ExpireClaim implements ShouldQueue
{
  use Queueable;

  public function __construct(public int $playingId, public int $expiresAt) {}

  public function handle(ClaimService $claims): void
  {
    $claims->expire($this->playingId, $this->expiresAt);
  }
}

<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A player's seat was freed without them asking, sent on their own
 * `private-App.Models.User.{id}` channel: they are no longer on the table
 * channel's seats, and may not be listening there by the time they look.
 * `reason` says why (`start_timeout`: they didn't press Start in time,
 * `TableSeatService::expireStart()`); `kibitzing` whether they stay at the
 * table as a watcher (never yet: there are no kibitzers).
 *
 * Same delivery as `TableUpdated`: queued, sent only once the transaction
 * commits.
 */
class UnseatedFromTable implements ShouldBroadcast, ShouldDispatchAfterCommit
{
  use Dispatchable, InteractsWithSockets;

  /** The table waited for their Start for `bridge.start_seconds`. */
  public const REASON_START_TIMEOUT = 'start_timeout';

  public function __construct(
    public int $userId,
    public int $tableId,
    public string $reason,
    public bool $kibitzing = false,
  ) {}

  /**
   * @return array<int, \Illuminate\Broadcasting\Channel>
   */
  public function broadcastOn(): array
  {
    return [new PrivateChannel('App.Models.User.'.$this->userId)];
  }

  /**
   * @return array{table_id: int, reason: string, kibitzing: bool}
   */
  public function broadcastWith(): array
  {
    return [
      'table_id' => $this->tableId,
      'reason' => $this->reason,
      'kibitzing' => $this->kibitzing,
    ];
  }
}

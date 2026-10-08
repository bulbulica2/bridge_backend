<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody was sent away from a table without asking, sent on their own
 * `private-App.Models.User.{id}` channel: the table channel no longer
 * counts them among its seats or kibitzers, and they may not be listening
 * there by the time they look. `reason` says why (`start_timeout`: they
 * didn't press Start in time, `TableSeatService::expireStart()`;
 * `kibitzers_off`: they were watching and a manager stopped the table
 * allowing it, `KibitzerService::removeAll()`); `kibitzing` whether they
 * stay at the table as a watcher (a freed seat at a table that allows
 * kibitzers).
 *
 * Same delivery as `TableUpdated`: queued, sent only once the transaction
 * commits.
 */
class UnseatedFromTable implements ShouldBroadcast, ShouldDispatchAfterCommit
{
  use Dispatchable, InteractsWithSockets;

  /** The table waited for their Start for `bridge.start_seconds`. */
  public const REASON_START_TIMEOUT = 'start_timeout';

  /** A manager turned `allow_kibitzers` off while they were watching. */
  public const REASON_KIBITZERS_OFF = 'kibitzers_off';

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

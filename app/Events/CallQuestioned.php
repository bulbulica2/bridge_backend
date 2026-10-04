<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An opponent (`asked_by`, their seat) asked what a call means: sent to its
 * bidder, a human, on their own `private-App.Models.User.{id}` channel, so
 * they can answer with `PUT /tables/{table}/calls/{index}/explanation`. A
 * robot bidder answers at once instead, and gets no event.
 *
 * Same delivery as `HandDealt`: queued, sent only once the transaction
 * commits.
 */
class CallQuestioned implements ShouldBroadcast, ShouldDispatchAfterCommit
{
  use Dispatchable, InteractsWithSockets;

  public function __construct(
    public int $userId,
    public int $tableId,
    public int $playingId,
    public int $index,
    public string $askedBy,
  ) {}

  /**
   * @return array<int, \Illuminate\Broadcasting\Channel>
   */
  public function broadcastOn(): array
  {
    return [new PrivateChannel('App.Models.User.'.$this->userId)];
  }

  /**
   * @return array<string, mixed>
   */
  public function broadcastWith(): array
  {
    return [
      'table_id' => $this->tableId,
      'playing_id' => $this->playingId,
      'index' => $this->index,
      'asked_by' => $this->askedBy,
    ];
  }
}

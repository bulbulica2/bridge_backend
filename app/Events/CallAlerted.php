<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A call was alerted, or its bidder explained it (an answer to a question,
 * or a fix): `index` is its place in the playing's `auction`, `explanation`
 * what it means, null for an alert with no description. Sent to one
 * opponent of the bidder on their own `private-App.Models.User.{id}`
 * channel — never the table channel, since the bidder's partner mustn't
 * see it (`AuctionService::alertTo()` picks the two).
 *
 * Same delivery as `HandDealt`: queued, sent only once the transaction
 * commits.
 */
class CallAlerted implements ShouldBroadcast, ShouldDispatchAfterCommit
{
  use Dispatchable, InteractsWithSockets;

  public function __construct(
    public int $userId,
    public int $tableId,
    public int $playingId,
    public int $index,
    public ?string $explanation,
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
      'explanation' => $this->explanation,
    ];
  }
}

<?php

namespace App\Events;

use App\Models\BoardTable;
use App\Services\PlayingStateService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One player's 13 cards for a board just dealt, sent on that player's own
 * `private-App.Models.User.{id}` channel — never the table channel, which a
 * player who has left may still be subscribed to.
 *
 * Same delivery as `TableUpdated`: queued, sent only once the transaction
 * commits, payload snapshotted at dispatch.
 */
class HandDealt implements ShouldBroadcast, ShouldDispatchAfterCommit
{
  use Dispatchable, InteractsWithSockets;

  public int $userId;

  public int $tableId;

  public int $playingId;

  public string $seat;

  /**
   * @var list<array{id: int, suit: string, rank: int, rank_name: string}>
   */
  public array $hand;

  public function __construct(BoardTable $playing, int $userId, string $seat)
  {
    $this->userId = $userId;
    $this->tableId = (int) $playing->table_id;
    $this->playingId = (int) $playing->getKey();
    $this->seat = $seat;
    $this->hand = app(PlayingStateService::class)->hand($playing, $seat);
  }

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
      'my_seat' => $this->seat,
      'hand' => $this->hand,
    ];
  }
}

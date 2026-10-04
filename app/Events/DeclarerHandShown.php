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
 * A robot has won the contract and its partner, dummy, is a human, who plays
 * both hands (`PlayingStateService::dummyPlaysForDeclarer()`): declarer's
 * 13 cards, sent when the auction ends on that human's own
 * `private-App.Models.User.{id}` channel — never the table channel, since the
 * defenders mustn't see them. The auction usually ends on a robot's call, so
 * no HTTP answer of the human's carries them yet.
 *
 * Same delivery as `HandDealt`: queued, sent only once the transaction
 * commits, payload snapshotted at dispatch.
 */
class DeclarerHandShown implements ShouldBroadcast, ShouldDispatchAfterCommit
{
  use Dispatchable, InteractsWithSockets;

  public int $userId;

  public int $tableId;

  public int $playingId;

  public string $seat;

  public string $declarer;

  /**
   * @var list<array{id: int, suit: string, rank: int, rank_name: string}>
   */
  public array $declarerHand;

  public function __construct(BoardTable $playing, int $userId, string $seat)
  {
    $this->userId = $userId;
    $this->tableId = (int) $playing->table_id;
    $this->playingId = (int) $playing->getKey();
    $this->seat = $seat;
    $this->declarer = $playing->declarer_seat;
    $this->declarerHand = app(PlayingStateService::class)->hand($playing, $playing->declarer_seat);
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
      'declarer' => $this->declarer,
      'declarer_hand' => $this->declarerHand,
    ];
  }
}

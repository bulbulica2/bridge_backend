<?php

namespace App\Events;

use App\Http\Resources\BoardMessageResource;
use App\Models\BoardMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A chat message (`BoardChatService`), sent to one player who may read it
 * — its sender included — on their own `private-App.Models.User.{id}`
 * channel: never the table channel, since an `opponents` message mustn't
 * reach the sender's partner.
 *
 * Same delivery as `HandDealt`: queued, sent only once the transaction
 * commits, with the message snapshotted here.
 */
class BoardMessageSent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
  use Dispatchable, InteractsWithSockets;

  /**
   * @var array<string, mixed>
   */
  public array $message;

  public function __construct(
    public int $userId,
    public int $tableId,
    public int $playingId,
    BoardMessage $message,
  ) {
    $this->message = json_decode(json_encode(new BoardMessageResource($message)), true);
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
      'message' => $this->message,
    ];
  }
}

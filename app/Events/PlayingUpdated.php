<?php

namespace App\Events;

use App\Http\Resources\PlayingResource;
use App\Models\Table;
use App\Services\PlayingStateService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The state of a table's playing changed: a board was dealt, a call made, a
 * card played or a claim made, answered or withdrawn. Goes to
 * `private-table.{id}` with the **public** part of
 * `GET /tables/{table}/playing` only — no hand, no `my_seat` — in its compact
 * shape (`PlayingResource::compact()`: cards and bids as ids), which keeps a
 * whole finished board under hosted Pusher's 10 KB per message. The table
 * channel is authorised once, at subscribe time, so a player who has left may
 * still be listening; anything private to one player goes on their own
 * channel (`HandDealt`).
 *
 * Same delivery as `TableUpdated`: queued, sent only once the transaction
 * commits, payload snapshotted at dispatch.
 */
class PlayingUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
  use Dispatchable, InteractsWithSockets;

  public int $tableId;

  /**
   * @var array<string, mixed>
   */
  public array $playing;

  public function __construct(Table $table)
  {
    $this->tableId = (int) $table->getKey();
    $this->playing = PlayingResource::compact(app(PlayingStateService::class)->publicState($table));
  }

  /**
   * @return array<int, \Illuminate\Broadcasting\Channel>
   */
  public function broadcastOn(): array
  {
    return [new PrivateChannel('table.'.$this->tableId)];
  }

  /**
   * @return array<string, mixed>
   */
  public function broadcastWith(): array
  {
    return ['playing' => $this->playing];
  }
}

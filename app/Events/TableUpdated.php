<?php

namespace App\Events;

use App\Http\Resources\TableResource;
use App\Models\Table;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A table's seats changed: somebody sat down, moved seat, left or was
 * kicked. Goes to everyone subscribed to `private-table.{id}`, carrying the
 * same TableResource shape the HTTP endpoints return, so a client has one
 * table shape to parse whichever way it arrived — except `can_manage`, which
 * depends on who asks and so is left out (withoutViewer()). A client keeps its
 * last value and refetches GET /tables/{table} when `moderated_by` changes.
 *
 * Fired from inside the seating transaction but only sent once it commits
 * (ShouldDispatchAfterCommit), so a move that rolls back announces nothing.
 * It is queued (ShouldBroadcast): a Reverb server that is down fails the job,
 * not the player's request. The payload is snapshotted here, at dispatch,
 * rather than re-read by the queue worker, so it shows the table as this
 * change left it even if a later change (or deleting the table) lands first.
 */
class TableUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
  use Dispatchable, InteractsWithSockets;

  public int $tableId;

  /**
   * @var array<string, mixed>
   */
  public array $table;

  public function __construct(Table $table)
  {
    $this->tableId = (int) $table->getKey();
    // through JSON, as an HTTP response would be: resolve() leaves the nested
    // seat and user resources as objects, still holding the models. There is
    // no viewer: the request user here is whoever made the change, and their
    // can_manage must not go to everyone, so it is left out
    $this->table = json_decode(json_encode((new TableResource($table->fresh(['seats.user'])))->withoutViewer()), true);
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
    return ['table' => $this->table];
  }
}

<?php

namespace App\Events;

use App\Models\UserBan;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user has just been banned, sent on their own
 * `private-App.Models.User.{id}` channel so an open client logs them out at
 * once and shows why: their sessions are already gone, so their next request
 * would be a 401 anyway.
 *
 * Same delivery as `TableUpdated`: queued, sent only once the transaction
 * commits, payload snapshotted at dispatch.
 */
class UserBanned implements ShouldBroadcast, ShouldDispatchAfterCommit
{
  use Dispatchable, InteractsWithSockets;

  public int $userId;

  /**
   * @var array{reason: string, until: string, banned_at: string}
   */
  public array $ban;

  public function __construct(UserBan $ban)
  {
    $this->userId = (int) $ban->user_id;
    $this->ban = $ban->toOwnArray();
  }

  /**
   * @return array<int, \Illuminate\Broadcasting\Channel>
   */
  public function broadcastOn(): array
  {
    return [new PrivateChannel('App.Models.User.'.$this->userId)];
  }

  /**
   * @return array{reason: string, until: string, banned_at: string}
   */
  public function broadcastWith(): array
  {
    return $this->ban;
  }
}

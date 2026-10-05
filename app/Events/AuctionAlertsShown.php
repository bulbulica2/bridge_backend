<?php

namespace App\Events;

use App\Broadcasting\PusherBody;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The auction is over, so its meaning is no longer unauthorised information
 * for anyone: partner's alerts, which this player couldn't see during the
 * auction, each as `{index, explanation}` (`index` its place in the
 * playing's `auction`). Sent once, when the auction ends, on the player's
 * own `private-App.Models.User.{id}` channel, and only when partner alerted
 * something: the auction usually ends on a robot's call, and the table
 * channel carries no alerts.
 *
 * A long auction can hold more alerts than one broadcast may carry, so
 * `split()` packs them into as few events as fit `PusherBody::BUDGET`.
 *
 * Same delivery as `HandDealt`: queued, sent only once the transaction
 * commits.
 */
class AuctionAlertsShown implements ShouldBroadcast, ShouldDispatchAfterCommit
{
  use Dispatchable, InteractsWithSockets;

  /**
   * @param  list<array{index: int, explanation: string|null}>  $alerts
   */
  public function __construct(
    public int $userId,
    public int $tableId,
    public int $playingId,
    public array $alerts,
  ) {}

  /**
   * `$alerts` for one player, in as few events as keep each within the
   * broadcast budget, in auction order.
   *
   * @param  list<array{index: int, explanation: string|null}>  $alerts
   * @return list<self>
   */
  public static function split(int $userId, int $tableId, int $playingId, array $alerts): array
  {
    $events = [];
    $part = [];

    foreach ($alerts as $alert) {
      $bigger = new self($userId, $tableId, $playingId, [...$part, $alert]);

      if ($part !== [] && strlen(PusherBody::of($bigger)) > PusherBody::BUDGET) {
        $events[] = new self($userId, $tableId, $playingId, $part);
        $part = [$alert];
      } else {
        $part[] = $alert;
      }
    }

    if ($part !== []) {
      $events[] = new self($userId, $tableId, $playingId, $part);
    }

    return $events;
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
      'alerts' => $this->alerts,
    ];
  }
}

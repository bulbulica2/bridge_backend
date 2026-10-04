<?php

namespace App\Broadcasting;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * The body Laravel's Pusher broadcaster POSTs to Reverb (or hosted Pusher)
 * for one of this app's events: its name, its data and its channels, the
 * data being a JSON **string** inside the JSON, so every quote and backslash
 * in it is escaped once more. Hosted Pusher refuses an event over `LIMIT`,
 * and Reverb an HTTP request over `reverb.servers.reverb.max_request_size`
 * (request line and headers included); every event here is kept within
 * `BUDGET`, which leaves room for those.
 *
 * Every event of this app has `broadcastWith()` and no `broadcastAs()`, so
 * its name is its class, as `BroadcastEvent` sends it.
 */
class PusherBody
{
  /** Hosted Pusher's limit on one event, in bytes. */
  public const LIMIT = 10_000;

  /** What a body may take of `LIMIT`, the rest being the HTTP request around it. */
  public const BUDGET = 9_000;

  public static function of(ShouldBroadcast $event): string
  {
    return json_encode([
      'name' => $event::class,
      'data' => json_encode($event->broadcastWith()),
      'channels' => array_map(fn ($channel) => (string) $channel, $event->broadcastOn()),
    ]);
  }
}

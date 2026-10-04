<?php

namespace App\Listeners;

use App\Broadcasting\PusherBody;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

/**
 * A broadcast that fails is a state nobody at the table sees: their screens
 * stay on the last one that got through until they reload. The queue already
 * reports the exception ("Pusher error: Payload too large."), but not which
 * event, table or user it was, nor how big: this logs that, at `error`, for
 * every broadcast job marked failed. Not queued, so it can't fail the same way.
 */
class LogFailedBroadcast
{
  public function handle(JobFailed $failed): void
  {
    $payload = $failed->job->payload();

    if (($payload['data']['commandName'] ?? null) !== BroadcastEvent::class) {
      return;
    }

    $event = unserialize($payload['data']['command'])->event;

    Log::error('Broadcast failed: '.class_basename($event).' never reached its channel.', [
      'event' => $event::class,
      'channels' => array_map(fn ($channel) => (string) $channel, $event->broadcastOn()),
      'table_id' => $event->tableId ?? null,
      'user_id' => $event->userId ?? null,
      'bytes' => strlen(PusherBody::of($event)),
      'error' => $failed->exception->getMessage(),
    ]);
  }
}

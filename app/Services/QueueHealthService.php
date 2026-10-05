<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whether a `queue:work` is running, which nothing else can tell: a stopped
 * worker leaves the tables looking live (the last state stays on screen)
 * while robots, broadcasts, claim expiry and the next deal all wait. Every
 * worker writes a heartbeat to the cache as it loops (`beat()`, from the
 * `BeatQueueHeartbeat` listener), at most every `BEAT_SECONDS`, and
 * `GET /api/health` reports it (`status()`): running while the last one is
 * under `STALE_SECONDS` old. A singleton, so a worker remembers its last
 * beat without asking the cache.
 */
class QueueHealthService
{
  public const KEY = 'bridge:queue:heartbeat';

  public const BEAT_SECONDS = 10;

  public const STALE_SECONDS = 60;

  private ?int $lastBeat = null;

  /**
   * Note that this worker is alive, unless it did so under `BEAT_SECONDS`
   * ago. A cache that fails is reported, never thrown: the heartbeat must
   * not be what stops the worker.
   */
  public function beat(): void
  {
    $now = now()->getTimestamp();

    if ($this->lastBeat !== null && $now - $this->lastBeat < self::BEAT_SECONDS) {
      return;
    }

    try {
      Cache::forever(self::KEY, $now);
      $this->lastBeat = $now;
    } catch (Throwable $e) {
      report($e);
    }
  }

  /**
   * @return array{running: bool, last_seen_at: ?string}
   */
  public function status(): array
  {
    $last = Cache::get(self::KEY);

    return [
      'running' => $last !== null && now()->getTimestamp() - $last < self::STALE_SECONDS,
      'last_seen_at' => $last === null ? null : Carbon::createFromTimestamp($last)->toIso8601ZuluString(),
    ];
  }
}

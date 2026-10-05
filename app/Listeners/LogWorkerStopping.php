<?php

namespace App\Listeners;

use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Log;

/**
 * A `queue:work` that stops leaves the robots, the broadcasts, claim expiry
 * and the next deal waiting until it is started again, and the worker
 * itself says nothing: this logs every stop, at `warning`, with its exit
 * code, what that code means and the worker's PHP memory. Not queued: the
 * worker is on its way out.
 */
class LogWorkerStopping
{
  private const REASONS = [
    Worker::EXIT_SUCCESS => 'asked to stop: queue:restart, --max-jobs, --max-time, --stop-when-empty, a signal or a lost database connection',
    Worker::EXIT_ERROR => 'a job ran past its timeout',
    Worker::EXIT_MEMORY_LIMIT => 'its PHP memory reached --memory',
  ];

  public function handle(WorkerStopping $event): void
  {
    Log::warning("Queue worker stopped (exit code $event->status).", [
      'status' => $event->status,
      'reason' => self::REASONS[$event->status] ?? 'unknown',
      'memory_mb' => self::megabytes(memory_get_usage(true)),
      'peak_memory_mb' => self::megabytes(memory_get_peak_usage(true)),
      // the --memory option, which arrives as a string
      'memory_limit_mb' => $event->workerOptions === null ? null : (int) $event->workerOptions->memory,
    ]);
  }

  public static function megabytes(int $bytes): float
  {
    return round($bytes / 1048576, 1);
  }
}

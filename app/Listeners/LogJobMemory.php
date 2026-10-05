<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Log;

/**
 * With `bridge.log_job_memory` on, a `debug` line per job the queue runs:
 * which job, and the worker's PHP memory after it. A memory that keeps
 * climbing job after job is a leak, and the job names say where. Off by
 * default: the robots alone run a job a second.
 */
class LogJobMemory
{
  public function handle(JobProcessed $event): void
  {
    if (! config('bridge.log_job_memory')) {
      return;
    }

    Log::debug('Queue job processed: '.$event->job->resolveName().'.', [
      'memory_mb' => LogWorkerStopping::megabytes(memory_get_usage(true)),
      'used_mb' => LogWorkerStopping::megabytes(memory_get_usage()),
    ]);
  }
}

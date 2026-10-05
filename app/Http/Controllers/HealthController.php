<?php

namespace App\Http\Controllers;

use App\Services\QueueHealthService;
use Illuminate\Http\JsonResponse;

class HealthController extends BaseController
{
  /**
   * GET /api/health: whether a queue worker is running (public). Always a
   * 200: the answer is in `data.queue.running`.
   */
  public function show(QueueHealthService $health): JsonResponse
  {
    $queue = $health->status();

    return $this->sendResponse(['queue' => $queue], $queue['running']
      ? 'The queue worker is running.'
      : 'The queue worker is not running: robots, live updates, claim expiry and the next deal wait for it.');
  }
}

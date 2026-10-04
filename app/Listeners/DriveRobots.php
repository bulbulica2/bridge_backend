<?php

namespace App\Listeners;

use App\Events\PlayingUpdated;
use App\Models\Table;
use App\Services\RobotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;

/**
 * Moves the robots: after every `PlayingUpdated` (sent only once its
 * transaction commits), and `bridge.robot_delay_seconds` later so a human can
 * follow, one robot makes the move the table is waiting for, if it is a
 * robot's (`RobotService::act()`). That move sends its own `PlayingUpdated`,
 * so a run of robot moves is a chain of these jobs, one move each.
 */
class DriveRobots implements ShouldQueue
{
  /**
   * Tables waiting for a move while one is being made in this process.
   *
   * @var list<int>
   */
  private static array $pending = [];

  private static bool $driving = false;

  public function __construct(private RobotService $robots) {}

  /**
   * `bridge.robot_delay_seconds`, but a robot's answer to a pending claim
   * comes in time however long that is: a second before the claim expires
   * at the latest (or at once with less left), since silence would reject it.
   */
  public function withDelay(PlayingUpdated $event): int
  {
    $delay = (int) config('bridge.robot_delay_seconds');
    $expiresAt = $event->playing['claim']['expires_at'] ?? null;

    if ($expiresAt === null) {
      return $delay;
    }

    $left = (int) floor(now()->diffInSeconds(Carbon::parse($expiresAt), false));

    return max(0, min($delay, $left - 1));
  }

  public function handle(PlayingUpdated $event): void
  {
    self::$pending[] = $event->tableId;

    // on the sync queue (the tests) a robot's move runs the next one from
    // inside itself, and a board played out by robots would nest 52 deep:
    // the outermost call makes them one after another instead
    if (self::$driving) {
      return;
    }

    self::$driving = true;

    try {
      while (($tableId = array_shift(self::$pending)) !== null) {
        $table = Table::find($tableId);

        if ($table !== null) {
          $this->robots->act($table);
        }
      }
    } finally {
      self::$driving = false;
      self::$pending = [];
    }
  }
}

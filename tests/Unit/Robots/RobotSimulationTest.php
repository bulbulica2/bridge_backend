<?php

namespace Tests\Unit\Robots;

use App\auxiliary\Seats;
use App\Robots\RobotCardPlayer;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Robots\Support\RobotTable;
use Tests\Unit\Robots\Support\V1CardPlayer;

/**
 * Robots only: four robots bid the same random deals and play each one
 * out three times, today's card play against the first robots' ("v1"):
 * v1 on both sides, today's declarer against v1 defenders, and v1
 * declarer against today's defenders. Today's declarer must make more
 * contracts than v1's, and today's defenders must beat more of them.
 *
 * The deals are seeded, so the counts don't change from run to run.
 * `docs/ROBOTS.md` quotes a larger run of the same comparison.
 */
class RobotSimulationTest extends TestCase
{
  use MakesCards;

  private const DEALS = 60;

  public function test_robots_make_more_contracts_than_v1_and_beat_more_as_defenders(): void
  {
    mt_srand(67);

    $v1 = fn (array $state) => V1CardPlayer::choose($state);
    $v2 = fn (array $state) => RobotCardPlayer::choose($state);
    $made = ['v1 v v1' => 0, 'v2 v v1' => 0, 'v1 v v2' => 0];
    $boards = 0;

    for ($deal = 0; $deal < self::DEALS; $deal++) {
      $hands = $this->deal();
      $contract = RobotTable::auction($hands, Seats::SEATS[$deal % 4]);

      if ($contract === null) {
        continue;
      }

      $boards++;
      $target = $contract['level'] + 6;

      foreach (['v1 v v1' => [$v1, $v1], 'v2 v v1' => [$v2, $v1], 'v1 v v2' => [$v1, $v2]] as $match => [$declarer, $defenders]) {
        $made[$match] += RobotTable::play($hands, $contract, $declarer, $defenders) >= $target ? 1 : 0;
      }
    }

    $report = json_encode(['boards' => $boards, 'made' => $made]);

    $this->assertGreaterThanOrEqual(self::DEALS * 0.8, $boards, $report);
    $this->assertGreaterThanOrEqual($made['v1 v v1'] + intdiv($boards, 10), $made['v2 v v1'], "declarer play: $report");
    $this->assertLessThan($made['v1 v v1'], $made['v1 v v2'], "defence: $report");
  }
}

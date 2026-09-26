<?php

namespace Tests\Unit;

use App\auxiliary\Vulnerability;
use App\Models\Bid;
use App\Services\ScoringService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Duplicate scoring on its own: no database.
 */
class ScoringTest extends TestCase
{
  /**
   * contract, doubled, vulnerable, tricks won, expected score for declarer
   */
  public static function scores(): array
  {
    return [
      // the §6 examples
      '4S vul just made' => ['4S', 0, true, 10, 620],
      '4S vul making 5' => ['4S', 0, true, 11, 650],
      '3NTX not vul down 2' => ['3NT', 1, false, 7, -300],
      '3NTX not vul down 3' => ['3NT', 1, false, 6, -500],

      // part-scores
      '2H just made' => ['2H', 0, false, 8, 110],
      '1NT+1' => ['1NT', 0, false, 8, 120],
      '3C just made' => ['3C', 0, false, 9, 110],
      '2D+2' => ['2D', 0, true, 10, 130],

      // game and slam
      '3NT just made, not vul' => ['3NT', 0, false, 9, 400],
      '3NT just made, vul' => ['3NT', 0, true, 9, 600],
      '5C just made, not vul' => ['5C', 0, false, 11, 400],
      '6S just made, not vul' => ['6S', 0, false, 12, 980],
      '6S just made, vul' => ['6S', 0, true, 12, 1430],
      '7NT just made, vul' => ['7NT', 0, true, 13, 2220],

      // doubled and redoubled, made
      '2HX just made, not vul' => ['2H', 1, false, 8, 470],
      '1NTXX just made, vul' => ['1NT', 2, true, 7, 760],
      '1CX just made, not vul' => ['1C', 1, false, 7, 140],
      '2HX+1, not vul' => ['2H', 1, false, 9, 570],
      '2HX+1, vul' => ['2H', 1, true, 9, 870],
      '2HXX+1, not vul' => ['2H', 2, false, 9, 840],
      '2HXX+1, vul' => ['2H', 2, true, 9, 1240],

      // undertricks, doubled, not vul
      'X nv down 1' => ['4S', 1, false, 9, -100],
      'X nv down 2' => ['4S', 1, false, 8, -300],
      'X nv down 3' => ['4S', 1, false, 7, -500],
      'X nv down 4' => ['4S', 1, false, 6, -800],
      // doubled, vul
      'X vul down 1' => ['4S', 1, true, 9, -200],
      'X vul down 2' => ['4S', 1, true, 8, -500],
      'X vul down 3' => ['4S', 1, true, 7, -800],
      'X vul down 4' => ['4S', 1, true, 6, -1100],
      // redoubled
      'XX nv down 1' => ['4S', 2, false, 9, -200],
      'XX vul down 4' => ['4S', 2, true, 6, -2200],
      // undoubled
      'nv down 1' => ['4S', 0, false, 9, -50],
      'nv down 3' => ['4S', 0, false, 7, -150],
      'vul down 1' => ['4S', 0, true, 9, -100],
      'vul down 3' => ['4S', 0, true, 7, -300],
      'vul down 13' => ['7NT', 0, true, 0, -1300],
    ];
  }

  #[DataProvider('scores')]
  public function test_score(string $contract, int $doubled, bool $vulnerable, int $tricksWon, int $expected): void
  {
    $board = $vulnerable ? Vulnerability::NS : Vulnerability::NONE;

    $this->assertSame($expected, ScoringService::score($this->bid($contract), $doubled, 'N', $board, $tricksWon));
  }

  public function test_vulnerability_is_the_declaring_sides(): void
  {
    // 3NT just made: 600 vulnerable, 400 not
    $this->assertSame(600, ScoringService::score($this->bid('3NT'), 0, 'S', Vulnerability::NS, 9));
    $this->assertSame(400, ScoringService::score($this->bid('3NT'), 0, 'E', Vulnerability::NS, 9));
    $this->assertSame(600, ScoringService::score($this->bid('3NT'), 0, 'W', Vulnerability::EW, 9));
    $this->assertSame(400, ScoringService::score($this->bid('3NT'), 0, 'N', Vulnerability::EW, 9));

    foreach (['N', 'E', 'S', 'W'] as $seat) {
      $this->assertSame(600, ScoringService::score($this->bid('3NT'), 0, $seat, Vulnerability::BOTH, 9));
      $this->assertSame(400, ScoringService::score($this->bid('3NT'), 0, $seat, Vulnerability::NONE, 9));
    }
  }

  public function test_only_a_contract_bid_is_scored(): void
  {
    $this->expectException(InvalidArgumentException::class);

    ScoringService::score($this->bid('P'), 0, 'N', Vulnerability::NONE, 7);
  }

  private function bid(string $name): Bid
  {
    $special = in_array($name, [Bid::PASS, Bid::DOUBLE, Bid::REDOUBLE], true);

    return new Bid([
      'suit' => $name,
      'special' => $special,
      'level' => $special ? null : (int) $name[0],
      'strain' => $special ? null : substr($name, 1),
    ]);
  }
}

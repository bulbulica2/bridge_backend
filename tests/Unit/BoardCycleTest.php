<?php

namespace Tests\Unit;

use App\auxiliary\Seats;
use App\auxiliary\Vulnerability;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BoardCycleTest extends TestCase
{
  /**
   * Standard duplicate board cycle: board number => [dealer, vulnerability].
   */
  public static function boards(): array
  {
    return [
      'board 1' => [1, 'N', ''],
      'board 2' => [2, 'E', 'N-S'],
      'board 3' => [3, 'S', 'E-W'],
      'board 4' => [4, 'W', 'N-S E-W'],
      'board 5' => [5, 'N', 'N-S'],
      'board 6' => [6, 'E', 'E-W'],
      'board 7' => [7, 'S', 'N-S E-W'],
      'board 8' => [8, 'W', ''],
      'board 9' => [9, 'N', 'E-W'],
      'board 10' => [10, 'E', 'N-S E-W'],
      'board 11' => [11, 'S', ''],
      'board 12' => [12, 'W', 'N-S'],
      'board 13' => [13, 'N', 'N-S E-W'],
      'board 14' => [14, 'E', ''],
      'board 15' => [15, 'S', 'N-S'],
      'board 16' => [16, 'W', 'E-W'],
    ];
  }

  #[DataProvider('boards')]
  public function test_dealer_and_vulnerability_follow_the_16_board_cycle(int $number, string $dealer, string $vulnerable): void
  {
    $this->assertSame($dealer, Seats::dealerForBoard($number));
    $this->assertSame($vulnerable, Vulnerability::forBoard($number));
  }

  #[DataProvider('boards')]
  public function test_cycle_repeats_after_16_boards(int $number, string $dealer, string $vulnerable): void
  {
    $this->assertSame($dealer, Seats::dealerForBoard($number + 16));
    $this->assertSame($vulnerable, Vulnerability::forBoard($number + 16));
  }

  public function test_board_number_below_one_is_rejected(): void
  {
    $this->expectException(InvalidArgumentException::class);

    Seats::dealerForBoard(0);
  }

  public function test_vulnerability_board_number_below_one_is_rejected(): void
  {
    $this->expectException(InvalidArgumentException::class);

    Vulnerability::forBoard(0);
  }
}

<?php

namespace Tests\Unit;

use App\auxiliary\Seats;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SeatsTest extends TestCase
{
  /**
   * seat => [next clockwise (left-hand opponent), partner]
   */
  public static function seats(): array
  {
    return [
      'North' => ['N', 'E', 'S'],
      'East' => ['E', 'S', 'W'],
      'South' => ['S', 'W', 'N'],
      'West' => ['W', 'N', 'E'],
    ];
  }

  #[DataProvider('seats')]
  public function test_next_is_the_seat_on_the_left(string $seat, string $next, string $partner): void
  {
    $this->assertSame($next, Seats::next($seat));
  }

  #[DataProvider('seats')]
  public function test_partner_sits_opposite(string $seat, string $next, string $partner): void
  {
    $this->assertSame($partner, Seats::partner($seat));
  }

  public function test_unknown_seat_is_rejected(): void
  {
    $this->expectException(InvalidArgumentException::class);

    Seats::next('X');
  }
}

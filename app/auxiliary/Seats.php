<?php

namespace App\auxiliary;

use InvalidArgumentException;

class Seats
{
  public const SEATS = ['N', 'E', 'S', 'W'];

  public const ALL_SEAT_NAMES = [
    'N' => 'North',
    'E' => 'East',
    'S' => 'South',
    'W' => 'West',
  ];

  /**
   * Dealer for a duplicate board number: 1→N, 2→E, 3→S, 4→W, then repeats.
   */
  public static function dealerForBoard(int $boardNumber): string
  {
    if ($boardNumber < 1) {
      throw new InvalidArgumentException("Board number must be at least 1, got $boardNumber.");
    }

    return self::SEATS[($boardNumber - 1) % 4];
  }

  /**
   * Next seat clockwise, i.e. the player on this seat's left (declarer's left makes the opening lead).
   */
  public static function next(string $seat): string
  {
    return self::SEATS[(self::indexOf($seat) + 1) % 4];
  }

  /**
   * The seat opposite (declarer's partner is dummy).
   */
  public static function partner(string $seat): string
  {
    return self::SEATS[(self::indexOf($seat) + 2) % 4];
  }

  private static function indexOf(string $seat): int
  {
    $index = array_search($seat, self::SEATS, true);

    if ($index === false) {
      throw new InvalidArgumentException("Unknown seat '$seat'.");
    }

    return $index;
  }
}

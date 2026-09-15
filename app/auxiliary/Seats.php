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
}

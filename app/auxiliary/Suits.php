<?php

namespace App\auxiliary;

use InvalidArgumentException;

class Suits
{
  public const SUIT_NAME = [
    'C' => 'Clubs',
    'D' => 'Diamonds',
    'H' => 'Hearts',
    'S' => 'Spades',
  ];

  public const ALL_SUIT_NAMES = [
    'C' => 'Clubs',
    'D' => 'Diamonds',
    'H' => 'Hearts',
    'S' => 'Spades',
    'NT' => 'No Trump',
  ];

  /**
   * Where a strain sits in the bidding order: clubs lowest, no trump highest.
   * `ALL_SUIT_NAMES` is already declared in that order, so a strain's position
   * in it is its rank.
   */
  public static function strainRank(string $strain): int
  {
    $index = array_search($strain, array_keys(self::ALL_SUIT_NAMES), true);

    if ($index === false) {
      throw new InvalidArgumentException("Unknown strain '$strain'.");
    }

    return $index;
  }
}

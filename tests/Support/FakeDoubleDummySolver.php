<?php

namespace Tests\Support;

use App\auxiliary\Seats;
use App\Solvers\DoubleDummySolver;

/**
 * A stand-in for DDS, so the suite needs no native library: a fixed table,
 * and after each lead its rank less two. Counts what it is asked to solve.
 */
class FakeDoubleDummySolver implements DoubleDummySolver
{
  public const TABLE = [
    'N' => ['C' => 1, 'D' => 2, 'H' => 3, 'S' => 4, 'NT' => 5],
    'E' => ['C' => 12, 'D' => 11, 'H' => 10, 'S' => 9, 'NT' => 8],
    'S' => ['C' => 1, 'D' => 2, 'H' => 3, 'S' => 4, 'NT' => 6],
    'W' => ['C' => 12, 'D' => 11, 'H' => 10, 'S' => 9, 'NT' => 7],
  ];

  public int $tables = 0;

  /**
   * @var list<array{0: string, 1: string}> each `leads()` call's declarer and strain
   */
  public array $leads = [];

  public function table(array $deal): array
  {
    $this->tables++;

    return self::TABLE;
  }

  public function leads(array $deal, string $declarer, string $strain): array
  {
    $this->leads[] = [$declarer, $strain];

    $tricks = [];

    foreach ($deal[Seats::next($declarer)] as $card) {
      $tricks[$card->id] = $card->rank - 2;
    }

    return $tricks;
  }
}

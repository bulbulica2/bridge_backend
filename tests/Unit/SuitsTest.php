<?php

namespace Tests\Unit;

use App\auxiliary\Suits;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SuitsTest extends TestCase
{
  public function test_strains_rank_clubs_lowest_through_no_trump_highest(): void
  {
    $ranks = array_map(
      fn (string $strain) => Suits::strainRank($strain),
      ['C', 'D', 'H', 'S', 'NT']
    );

    $this->assertSame([0, 1, 2, 3, 4], $ranks);
  }

  public function test_every_biddable_strain_has_a_rank(): void
  {
    foreach (array_keys(Suits::ALL_SUIT_NAMES) as $strain) {
      $this->assertIsInt(Suits::strainRank($strain));
    }
  }

  public function test_no_trump_is_not_a_card_suit(): void
  {
    // a deal has four suits; a contract has five strains
    $this->assertCount(4, Suits::SUIT_NAME);
    $this->assertCount(5, Suits::ALL_SUIT_NAMES);
    $this->assertArrayNotHasKey('NT', Suits::SUIT_NAME);
  }

  public function test_an_unknown_strain_is_rejected(): void
  {
    $this->expectException(InvalidArgumentException::class);

    Suits::strainRank('Z');
  }
}

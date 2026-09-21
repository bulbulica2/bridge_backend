<?php

namespace Tests\Feature\Database;

use App\Models\Bid;
use Database\Seeders\game\BidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Bid ranking is what auction legality is built on, so it must come from
 * `level` and `strain` and never from row order.
 */
class BidOrderingTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed(BidSeeder::class);
  }

  public function test_the_seeder_produces_38_calls(): void
  {
    $this->assertSame(38, Bid::count());
    $this->assertSame(3, Bid::specials()->count());
    $this->assertSame(35, Bid::contracts()->count());
  }

  public function test_special_calls_have_no_rank(): void
  {
    foreach (['P', 'X', 'XX'] as $call) {
      $bid = $this->bid($call);

      $this->assertTrue($bid->special);
      $this->assertNull($bid->level);
      $this->assertNull($bid->strain);
    }
  }

  public function test_every_contract_bid_has_a_level_and_a_strain(): void
  {
    $this->assertSame(0, Bid::contracts()->whereNull('level')->count());
    $this->assertSame(0, Bid::contracts()->whereNull('strain')->count());
    $this->assertSame(0, Bid::contracts()->whereNotBetween('level', [1, 7])->count());
  }

  public function test_a_higher_strain_at_the_same_level_wins(): void
  {
    $this->assertTrue($this->bid('2H')->isHigherThan($this->bid('2D')));
    $this->assertFalse($this->bid('2D')->isHigherThan($this->bid('2H')));
    $this->assertTrue($this->bid('2NT')->isHigherThan($this->bid('2S')));
  }

  public function test_a_higher_level_beats_any_strain(): void
  {
    $this->assertTrue($this->bid('3C')->isHigherThan($this->bid('2NT')));
    $this->assertFalse($this->bid('2NT')->isHigherThan($this->bid('3C')));
  }

  public function test_seven_no_trump_outranks_every_other_call(): void
  {
    $highest = $this->bid('7NT');

    foreach (Bid::contracts()->where('suit', '!=', '7NT')->get() as $other) {
      $this->assertTrue($highest->isHigherThan($other), "7NT should beat {$other->suit}");
    }
  }

  public function test_a_bid_does_not_outrank_itself(): void
  {
    $this->assertFalse($this->bid('4S')->isHigherThan($this->bid('4S')));
  }

  public function test_comparing_a_special_call_fails_loudly(): void
  {
    $this->expectException(LogicException::class);

    $this->bid('X')->isHigherThan($this->bid('1C'));
  }

  /**
   * Ranking must not depend on insertion order, so every bid here is looked
   * up by its (level, strain) pair rather than by id. Reordering the seeder
   * would leave this test passing while an id-based comparison broke.
   */
  public function test_ranking_is_independent_of_row_ids(): void
  {
    $ordered = Bid::contracts()->orderBy('level')->orderByRaw($this->strainOrder())->get();

    $this->assertSame('1C', $ordered->first()->suit);
    $this->assertSame('7NT', $ordered->last()->suit);

    $ordered->reduce(function (?Bid $previous, Bid $current) {
      if ($previous !== null) {
        $this->assertTrue(
          $current->isHigherThan($previous),
          "{$current->suit} should outrank {$previous->suit}"
        );
      }

      return $current;
    });
  }

  private function bid(string $call): Bid
  {
    return Bid::where('suit', $call)->firstOrFail();
  }

  /**
   * Order by strain without leaning on the id column.
   */
  private function strainOrder(): string
  {
    return "case strain when 'C' then 0 when 'D' then 1 when 'H' then 2 when 'S' then 3 else 4 end";
  }
}

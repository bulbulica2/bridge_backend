<?php

namespace Tests\Unit\Robots;

use App\Robots\DeclarerPlan;
use App\Robots\PlayView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Declarer's plan before the first card from dummy: S declares, W has led,
 * and it is dummy's (N's) turn. Hands are spades.hearts.diamonds.clubs.
 */
class DeclarerPlanTest extends TestCase
{
  use MakesCards;

  /**
   * contract, declarer, dummy, the opening lead, sure winners, losers,
   * ruffs, the line
   */
  public static function plans(): array
  {
    return [
      // ♠ A-K-Q 3, ♥ A-K-Q 3, ♦ A-K-Q-J 4
      '3NT with ten on top' => ['3NT', 'AK2.AQ3.K432.432', 'Q43.K42.AQJ5.765', 'CK', 10, 0, 0, DeclarerPlan::CASH],
      // ♠ A 1, ♥ A-K 2, ♣ A-K 2
      '3NT to develop' => ['3NT', 'A32.K32.KQJ.432', '54.A54.T5432.AK6', 'SK', 5, 0, 0, DeclarerPlan::DEVELOP],
      // ♠ 4, ♥ 2, ♦ 1; losers ♦ 2, ♣ 1 (the ace), three to spare
      '4♠ drawing trumps' => ['4S', 'AKQ32.A2.432.K32', 'J54.K43.A5.Q7654', 'HQ', 7, 3, 1, DeclarerPlan::DRAW],
      // losers ♥ 1, ♦ 4, ♣ 1; dummy ruffs three diamonds
      '4♠ ruffing in dummy' => ['4S', 'AKQ32.A32.8432.2', 'J54.K654.5.KQ765', 'CJ', 6, 6, 3, DeclarerPlan::RUFF],
      // losers ♠ 1, ♣ 5; S void in diamonds, dummy in clubs
      '4♥ cross-ruff' => ['4H', 'A32.AKQ4.-.A87652', 'Q54.J765.AK6543.-', 'CK', 8, 6, 4, DeclarerPlan::CROSSRUFF],
    ];
  }

  #[DataProvider('plans')]
  public function test_the_plan(string $contract, string $hand, string $dummy, string $lead, int $winners, int $losers, int $ruffs, string $line): void
  {
    $strain = substr($contract, 1);
    $state = [
      'my_seat' => 'S',
      'turn' => 'N',
      'contract' => ['declarer' => 'S', 'bid' => ['level' => (int) $contract[0], 'strain' => $strain]],
      'hand' => $this->cards($hand),
      'dummy_hand' => $this->cards($dummy),
      'tricks' => [],
      'current_trick' => [['seat' => 'W', 'card' => $this->card($lead)]],
    ];

    $plan = new DeclarerPlan(PlayView::fromState($state));

    $this->assertSame([$winners, $losers, $ruffs, $line], [$plan->winners, $plan->losers, $plan->ruffs, $plan->line]);
  }

  public function test_the_suit_to_develop(): void
  {
    // 3NT: ♣ K-Q-J-T opposite small promises three tricks once the ace is
    // knocked out; ♥ A-K-x-x opposite x-x-x only the fourth
    $state = [
      'my_seat' => 'S',
      'turn' => 'N',
      'contract' => ['declarer' => 'S', 'bid' => ['level' => 3, 'strain' => 'NT']],
      'hand' => $this->cards('A32.AK32.A32.KQJ'),
      'dummy_hand' => $this->cards('K54.654.K654.T98'),
      'tricks' => [],
      'current_trick' => [['seat' => 'W', 'card' => $this->card('SQ')]],
    ];

    $this->assertSame('C', (new DeclarerPlan(PlayView::fromState($state)))->developSuit);
  }
}

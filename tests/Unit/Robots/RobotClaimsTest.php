<?php

namespace Tests\Unit\Robots;

use App\Robots\RobotClaims;
use PHPUnit\Framework\TestCase;

/**
 * The robots' answers to claims (`docs/ROBOTS.md`), over in-memory state
 * shaped like `PlayingStateService::stateFor()`: no database. Every board
 * here is 4S by N (dummy S) with ten tricks played and three to go.
 */
class RobotClaimsTest extends TestCase
{
  use MakesCards;

  public function test_a_concession_is_always_accepted(): void
  {
    // declarer concedes: the defender takes all three
    $this->assertTrue(RobotClaims::accepts($this->state('E', 'A.-.K2.-', 'N', 0, 'KQ.-.A.-')));

    // a defender concedes the rest to declarer, even one the robot could beat
    $this->assertTrue(RobotClaims::accepts($this->state('N', 'KQ.-.A.-', 'E', 0, 'A.-.K2.-')));
  }

  public function test_a_defender_rejects_a_claim_that_takes_its_sure_winner(): void
  {
    // E holds the ace of trumps: declarer can't have all three
    $this->assertFalse(RobotClaims::accepts($this->state('E', 'A.-.K2.-', 'N', 3, 'KQ.-.A.-')));
  }

  public function test_a_defender_accepts_a_claim_that_leaves_it_its_sure_winners(): void
  {
    $this->assertTrue(RobotClaims::accepts($this->state('E', 'A.-.K2.-', 'N', 2, 'KQ.-.A.-')));

    // the king of diamonds isn't sure: the ace is out
    $this->assertSame(1, RobotClaims::sureWinners($this->state('E', 'A.-.K2.-', 'N', 2, 'KQ.-.A.-')));
  }

  public function test_a_claim_by_partner_counts_the_claimers_face_up_cards_as_ours(): void
  {
    // W claims 2 for the defence; E sees W's hand: SA (E) + SK (W) run, but
    // one hand has only one spade, and CA (W) makes a second
    $state = $this->state('E', 'A.-.K2.-', 'W', 2, 'K.-.-.AQ');

    $this->assertSame(2, RobotClaims::sureWinners($state));
    $this->assertTrue(RobotClaims::accepts($state));

    $this->assertFalse(RobotClaims::accepts($this->state('E', 'A.-.K2.-', 'W', 1, 'K.-.-.AQ')));
  }

  public function test_winners_a_face_up_opponent_can_ruff_dont_count(): void
  {
    // declarer's face-up hand is all trumps: E's top hearts would be ruffed
    $state = $this->state('E', '-.AKQ.-.-', 'N', 3, 'KQ2.-.-.-');

    $this->assertSame(0, RobotClaims::sureWinners($state));
    $this->assertTrue(RobotClaims::accepts($state));

    // with a heart left, declarer can't ruff them
    $this->assertSame(3, RobotClaims::sureWinners($this->state('E', '-.AKQ.-.-', 'N', 3, 'KQ.2.-.-')));
  }

  public function test_declarer_counts_dummys_winners(): void
  {
    // a defender claims 2; declarer N holds SK and dummy SA and SQ: three
    // spades on top, two cards in the longer hand
    $state = $this->state('N', 'K.-.-.32', 'E', 2, 'J.-.-.AK', dummy: 'AQ.-.-.4');

    $this->assertSame(2, RobotClaims::sureWinners($state));
    $this->assertFalse(RobotClaims::accepts($state));
  }

  /**
   * @return array<string, mixed>
   */
  private function state(string $me, string $hand, string $claimer, int $tricks, string $claimerHand, string $dummy = '2.2.2.2'): array
  {
    return [
      'my_seat' => $me,
      'contract' => ['declarer' => 'N', 'bid' => ['strain' => 'S']],
      'hand' => $this->cards($hand),
      'dummy_hand' => $this->cards($dummy),
      'tricks' => array_fill(0, 10, ['cards' => []]),
      'current_trick' => [],
      'claim' => ['seat' => $claimer, 'tricks' => $tricks, 'hand' => $this->cards($claimerHand), 'accepted' => []],
    ];
  }
}

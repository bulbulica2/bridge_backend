<?php

namespace Tests\Unit\Robots;

use App\auxiliary\Seats;
use App\Robots\RobotClaims;
use App\Robots\RobotHand;
use App\Services\CardPlayService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The robots' claims and answers to claims (`docs/ROBOTS.md`), over
 * in-memory state shaped like `PlayingStateService::stateFor()`: no
 * database. The boards of `state()` are 4S by N (dummy S) with ten tricks
 * gone by (their cards unknown, so the double dummy check can't run and
 * the sure winners decide); those of `ending()` are whole deals.
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

  public function test_double_dummy_rejects_a_finesse_that_loses(): void
  {
    // 3NT by N, two tricks left, dummy S on lead: N claims both with the
    // ♥A-Q, but E holds the king behind them. The sure winners E sees are
    // none, yet it takes a trick whatever N does.
    $state = $this->ending('NT', ['N' => '-.AQ.-.-', 'E' => '-.K4.-.-', 'S' => '-.32.-.-', 'W' => '-.-.-.32'], 'S', me: 'E', claimer: 'N', tricks: 2);

    $this->assertSame(0, RobotClaims::sureWinners($state));
    $this->assertSame(1, RobotClaims::doubleDummy($state));
    $this->assertFalse(RobotClaims::accepts($state));

    // with the king in front of them, the finesse works: accepted
    $state = $this->ending('NT', ['N' => '-.AQ.-.-', 'E' => '-.-.-.32', 'S' => '-.32.-.-', 'W' => '-.K4.-.-'], 'S', me: 'W', claimer: 'N', tricks: 2);
    $this->assertSame(0, RobotClaims::doubleDummy($state));
    $this->assertTrue(RobotClaims::accepts($state));
  }

  public function test_double_dummy_accepts_when_a_winner_never_gets_its_turn(): void
  {
    // 3NT by N, N on lead with the ♥A-K: E's last two spades would win two
    // spade tricks, but no spade is ever led
    $state = $this->ending('NT', ['N' => '-.AK.-.-', 'E' => 'A2.-.-.-', 'S' => '-.-.-.32', 'W' => '-.-.32.-'], 'N', me: 'E', claimer: 'N', tricks: 2);

    $this->assertSame(2, RobotClaims::sureWinners($state));
    $this->assertSame(0, RobotClaims::doubleDummy($state));
    $this->assertTrue(RobotClaims::accepts($state));
  }

  /**
   * contract, the hands, who is on lead, the claim the robot acting for
   * that seat makes (null for none)
   */
  public static function claims(): array
  {
    return [
      'all top winners' => ['NT', ['N' => '-.AK.-.-', 'E' => 'A2.-.-.-', 'S' => '-.-.-.32', 'W' => '-.-.32.-'], 'N', 2],
      'dummy\'s top winners, declarer playing them' => ['NT', ['N' => '-.-.-.32', 'E' => 'A2.-.-.-', 'S' => '-.AK.-.-', 'W' => '-.-.32.-'], 'S', 2],
      'a card that isn\'t a winner' => ['NT', ['N' => '-.AQ.-.-', 'E' => '-.K4.-.-', 'S' => '-.32.-.-', 'W' => '-.-.-.32'], 'N', null],
      'the last trump drawn first' => ['S', ['N' => 'A.A.-.-', 'E' => 'K.2.-.-', 'S' => '-.-.-.32', 'W' => '-.-.32.-'], 'N', 2],
      'two trumps out, one to draw them' => ['S', ['N' => 'A.AK.-.-', 'E' => 'KQ.2.-.-', 'S' => '-.-.-.432', 'W' => '-.-.432.-'], 'N', null],
      'a defender with the rest' => ['NT', ['N' => '-.-.-.32', 'E' => '-.AK.-.-', 'S' => '-.-.32.-', 'W' => '32.-.-.-'], 'E', 2],
    ];
  }

  /**
   * @param  array<string, string>  $hands
   */
  #[DataProvider('claims')]
  public function test_when_a_robot_claims(string $strain, array $hands, string $leader, ?int $claim): void
  {
    $state = $this->ending($strain, $hands, $leader, me: CardPlayService::actingSeat($leader, 'N'));

    $this->assertSame($claim, RobotClaims::claim($state));
  }

  public function test_no_claim_in_the_middle_of_a_trick(): void
  {
    $state = $this->ending('NT', ['N' => '-.AK.-.-', 'E' => 'A2.-.-.-', 'S' => '-.-.-.32', 'W' => '-.-.32.-'], 'W', me: 'N');
    $state['turn'] = 'N';
    $state['current_trick'] = [['seat' => 'W', 'card' => $this->card('D3')]];

    $this->assertNull(RobotClaims::claim($state));
  }

  /**
   * An ending of 3NT or 4♠ declared by N (dummy S): the hands left, the
   * rest of the pack played in the tricks before, `$leader` to lead, and
   * `$claimer`'s claim pending when there is one.
   *
   * @param  array<string, string>  $hands
   * @return array<string, mixed>
   */
  private function ending(string $strain, array $hands, string $leader, string $me, ?string $claimer = null, int $tricks = 0): array
  {
    $hands = array_map(fn ($hand) => $this->cards($hand), $hands);
    $held = array_column(array_merge(...array_values($hands)), 'id');
    $rest = array_values(array_filter($this->pack(), fn ($card) => ! in_array($card['id'], $held, true)));
    $played = [];

    foreach (array_chunk($rest, 4) as $cards) {
      $played[] = ['cards' => array_map(fn ($seat, $card) => ['seat' => $seat, 'card' => $card], Seats::SEATS, $cards)];
    }

    return [
      'my_seat' => $me,
      'turn' => $leader,
      'contract' => ['declarer' => 'N', 'bid' => ['level' => $strain === 'NT' ? 3 : 4, 'strain' => $strain]],
      'hand' => $hands[$me],
      'dummy_hand' => $hands['S'],
      'tricks' => $played,
      'current_trick' => [],
      'claim' => $claimer === null ? null : ['seat' => $claimer, 'tricks' => $tricks, 'hand' => $hands[$claimer], 'accepted' => []],
    ];
  }

  /**
   * @return list<array{id: int, suit: string, rank: int}>
   */
  private function pack(): array
  {
    $pack = [];

    foreach (['S', 'H', 'D', 'C'] as $suit) {
      foreach (RobotHand::RANKS as $rank) {
        $pack[] = ['id' => array_search($suit, ['C', 'D', 'H', 'S'], true) * 100 + $rank, 'suit' => $suit, 'rank' => $rank];
      }
    }

    return $pack;
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

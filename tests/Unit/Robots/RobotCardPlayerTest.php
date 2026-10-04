<?php

namespace Tests\Unit\Robots;

use App\auxiliary\Seats;
use App\Robots\Endgame;
use App\Robots\PlayView;
use App\Robots\RobotCardPlayer;
use App\Services\CardPlayService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The robots' card play (`docs/ROBOTS.md`), over in-memory state shaped like
 * `PlayingStateService::stateFor()`: no database. Hands are
 * spades.hearts.diamonds.clubs, written as dealt (cards played are taken
 * out); tricks are written lead first, `'W HQ, N H4, E H7, S HA'`, several
 * separated by `;`.
 */
class RobotCardPlayerTest extends TestCase
{
  use MakesCards;

  /**
   * contract, declarer, leader, the leader's hand, the lead
   */
  public static function openingLeads(): array
  {
    return [
      'top of a sequence' => ['H', 'N', 'E', '32.432.KQJ2.5432', 'DK'],
      'fourth best from the longest suit' => ['NT', 'N', 'E', 'K8532.432.Q2.432', 'S3'],
      'never underlead an ace against a suit' => ['H', 'N', 'E', 'A8532.432.Q2.432', 'C4'],
      'underleading an ace is fine in NT' => ['NT', 'N', 'E', 'A8532.432.Q2.432', 'S3'],
      'the ace when every suit is headed by one' => ['H', 'N', 'E', 'A8532.432.A2.A32', 'SA'],
      'low from three to an honour' => ['S', 'N', 'E', '5432.K32.T54.J53', 'H2'],
      'no trump lead while there is another suit' => ['S', 'N', 'E', 'KQJ32.2.5432.432', 'D2'],
    ];
  }

  #[DataProvider('openingLeads')]
  public function test_the_opening_lead(string $strain, string $declarer, string $leader, string $hand, string $lead): void
  {
    $state = $this->state($strain, $declarer, $leader, $this->cards($hand), null);

    $this->assertSame($this->card($lead)['id'], RobotCardPlayer::choose($state));
  }

  /**
   * contract, declarer, the trick so far (lead first), the hand to play
   * from, the robot's own hand when that is dummy's, dummy, the card
   */
  public static function follows(): array
  {
    return [
      'second hand low' => ['NT', 'S', 'S D4', '32.5.AK2.5432', null, '9.9.9.9', 'D2'],
      'third hand high, the cheaper of equals' => ['NT', 'S', 'W D2, N D3', '32.5.KQ5.5432', null, '9.9.9.9', 'DQ'],
      'third hand low under partner\'s winner' => ['NT', 'S', 'W DA, N D3', '32.5.KQ5.5432', null, '9.9.9.9', 'D5'],
      'third hand low when it can\'t beat the card' => ['NT', 'S', 'W D2, N DA', '32.5.KQ5.5432', null, '9.9.9.9', 'D5'],
      'fourth hand wins as cheaply as it can' => ['NT', 'S', 'W DT, N D3, E D4', '32.5.KJ2.5432', null, '9.9.9.9', 'DJ'],
      'fourth hand low under partner\'s winner' => ['NT', 'S', 'W D2, N DA, E D3', '32.5.K5.5432', null, '9.9.9.9', 'D5'],
      'ruff when void and the opponents are winning' => ['H', 'S', 'W SA, N S2, E S3', '-.KQ32.A5432.5432', null, '9.9.9.9', 'H2'],
      'over-ruff' => ['H', 'S', 'N C2, E C3, S H9', '5432.T3.A5432.-', null, '9.8.7.6', 'HT'],
      'discard low from the longest suit when unable to over-ruff' => ['H', 'S', 'N C2, E C3, S HA', '5432.T3.A5432.-', null, '9.8.7.6', 'D2'],
      'discard low under partner\'s winner, sparing a sure winner' => ['H', 'S', 'N C2, E CA, S C3', 'A32.T3.K5.-', null, '9.8.7.6', 'S2'],
      'declarer plays third hand high from dummy' => ['NT', 'S', 'S D2, W D3', '32.5.KQ5.5432', '9.9.9.9', null, 'DQ'],
    ];
  }

  #[DataProvider('follows')]
  public function test_following(
    string $strain,
    string $declarer,
    string $trick,
    string $hand,
    ?string $own,
    ?string $dummy,
    string $card
  ): void {
    $trick = $this->trick($trick);
    $turn = Seats::next(end($trick)['seat']);
    $isDummy = $turn === Seats::partner($declarer);

    // on dummy's turn the robot is declarer: its own cards are `hand`,
    // the ones it plays are dummy's
    $state = $isDummy
      ? $this->state($strain, $declarer, $turn, $this->cards($own), $this->cards($hand), $trick)
      : $this->state($strain, $declarer, $turn, $this->cards($hand), $dummy === null ? null : $this->cards($dummy), $trick);

    $this->assertSame($this->card($card)['id'], RobotCardPlayer::choose($state));
  }

  /**
   * Declarer (S) on lead after the first trick: contract, declarer's
   * hand, dummy's, the first trick, the lead.
   */
  public static function declarerLeads(): array
  {
    return [
      // 4♥ with no losers to ruff: draw trumps first
      'draw trumps with a master' => ['4H', 'A2.AKQ32.A32.432', 'K43.J54.K54.8765', 'W SQ, N S3, E S5, S SA', 'HA'],
      'draw trumps low without a master' => ['4H', 'A2.Q5432.AK2.432', 'K43.KJ6.543.8765', 'W SQ, N S3, E S5, S SA', 'H2'],
      // 3NT with the tricks on top: take them, the short hand's first
      'cash the short hand\'s winners first' => ['3NT', 'A2.AK.AK432.5432', 'K43.QJ65.65.A876', 'W SQ, N S3, E S5, S SA', 'HA'],
      'low to the short hand\'s master' => ['3NT', 'A2.AK2.A5432.432', 'K43.543.KQ.AK765', 'W SQ, N S3, E S5, S SA', 'D2'],
      // 4♠ with four losers: ruff diamonds in dummy before drawing trumps
      'shorten dummy for a ruff' => ['4S', 'AKQ32.32.8432.A2', 'J54.K654.5.KQ765', 'W CJ, N C5, E C8, S CA', 'D2'],
      'lead for dummy to ruff' => ['4S', 'AKQ32.32.8432.A2', 'J54.K9654.-.KQT76', 'W CJ, N C6, E C8, S CA', 'D2'],
      // 3NT with six on top: set up clubs
      'finesse: low towards the tenace' => ['3NT', 'A2.AK2.A5432.432', 'K43.543.K6.AQ765', 'W SQ, N S3, E S5, S SA', 'C2'],
      'lead towards an honour' => ['3NT', 'A2.AK2.A5432.432', 'K43.543.K6.K8765', 'W SQ, N S3, E S5, S SA', 'C2'],
      'run the top of a sequence towards the ace' => ['3NT', 'A2.AK2.A5432.QJT', 'K43.543.K6.A8765', 'W SQ, N S3, E S5, S SA', 'CQ'],
      'knock out the ace' => ['3NT', 'A2.AK2.A5432.KQJ', 'K43.543.K6.T8765', 'W SQ, N S3, E S5, S SA', 'CK'],
    ];
  }

  #[DataProvider('declarerLeads')]
  public function test_declarer_on_lead(string $contract, string $hand, string $dummy, string $first, string $lead): void
  {
    $strain = substr($contract, 1);
    $state = $this->state($strain, 'S', 'S', $this->cards($hand), $this->cards($dummy), [], $this->tricks($strain, $first), (int) $contract[0]);

    $this->assertSame($this->card($lead)['id'], RobotCardPlayer::choose($state));
  }

  public function test_crossruff_after_cashing_the_side_winners(): void
  {
    // 4♥ with four losers: S is void in diamonds, dummy in clubs, four
    // trumps each
    $hand = $this->cards('A32.AKQ4.-.A87652');
    $dummy = $this->cards('Q54.J765.AK6543.-');
    $first = $this->tricks('H', 'W CK, N S4, E C3, S CA');

    $state = $this->state('H', 'S', 'S', $hand, $dummy, [], $first, 4);
    $this->assertSame($this->card('SA')['id'], RobotCardPlayer::choose($state), 'side winners go first');

    $state = $this->state('H', 'S', 'S', $hand, $dummy, [], [...$first, ...$this->tricks('H', 'S SA, W S6, N S5, E S7')], 4);
    $this->assertSame($this->card('C2')['id'], RobotCardPlayer::choose($state), 'then a club for dummy to ruff');
  }

  /**
   * Declarer's third hand: dummy (N) plays after W, contract 3NT by S.
   * The trick so far, declarer's clubs, dummy's clubs, the card.
   */
  public static function finesses(): array
  {
    return [
      'finesse the queen' => ['S C2, W C4', '7652', 'AQ3', 'CQ'],
      'the ace when second hand shows out' => ['S C2, W D4', '7652', 'AQ3', 'CA'],
      'drop the queen with nine cards' => ['S C2, W C4', '7652', 'AKJ98', 'CK'],
      'finesse the jack with eight cards' => ['S C2, W C4', '7652', 'AKJ9', 'CJ'],
      'the king led towards' => ['S C2, W C4', '7652', 'K3', 'CK'],
      'let the queen run' => ['S CQ, W C4', 'QJT5', 'A93', 'C3'],
      'win when second hand covers' => ['S CQ, W CK', 'QJT5', 'A93', 'CA'],
    ];
  }

  #[DataProvider('finesses')]
  public function test_finesse_or_drop(string $trick, string $clubs, string $dummy, string $card): void
  {
    $state = $this->state('NT', 'S', 'N', $this->cards("A32.A32.A32.$clubs"), $this->cards("54.54.54.$dummy"), $this->trick($trick), [], 3);

    $this->assertSame($this->card($card)['id'], RobotCardPlayer::choose($state));
  }

  public function test_hold_up_a_lone_stopper_in_no_trump(): void
  {
    // 3NT by S with five tricks on top; W leads the ♠K. With six spades
    // between the hands, the rule of 7 lets one round go by
    $hand = $this->cards('A432.K32.KQJ.432');
    $dummy = $this->cards('65.A54.T5432.AK6');

    $state = $this->state('NT', 'S', 'S', $hand, $dummy, $this->trick('W SK, N S5, E S7'), [], 3);
    $this->assertSame($this->card('S2')['id'], RobotCardPlayer::choose($state));

    $state = $this->state('NT', 'S', 'S', $hand, $dummy, $this->trick('W SQ, N S6, E S8'), $this->tricks('NT', 'W SK, N S5, E S7, S S2'), 3);
    $this->assertSame($this->card('SA')['id'], RobotCardPlayer::choose($state));
  }

  public function test_no_hold_up_with_the_contract_on_top(): void
  {
    $state = $this->state('NT', 'S', 'S', $this->cards('A32.AK2.AK32.A32'), $this->cards('54.543.QJ54.KQJ5'), $this->trick('W SK, N S4, E S6'), [], 3);

    $this->assertSame($this->card('SA')['id'], RobotCardPlayer::choose($state));
  }

  public function test_duck_to_keep_an_entry_to_the_long_suit(): void
  {
    // 3NT by S: dummy's A-K-6-5-4-3 of clubs and nothing else to reach it
    // by; S leads a club and W follows low: dummy ducks
    $state = $this->state('NT', 'S', 'N', $this->cards('AK2.AKQ2.AKQ3.72'), $this->cards('543.43.54.AK6543'), $this->trick('S C2, W C8'), [], 3);
    $this->assertSame($this->card('C3')['id'], RobotCardPlayer::choose($state));

    // with the ♠A in dummy to come back by, it wins at once
    $state = $this->state('NT', 'S', 'N', $this->cards('K62.AKQ2.AKQ3.72'), $this->cards('A54.43.54.AK6543'), $this->trick('S C2, W C8'), [], 3);
    $this->assertSame($this->card('CK')['id'], RobotCardPlayer::choose($state));
  }

  /**
   * A defender following: contract, declarer, dummy's cards, the tricks
   * before, the trick so far, the hand, the card.
   */
  public static function defence(): array
  {
    return [
      'encourage with an honour on partner\'s lead' => ['4S', 'N', '432.765.T98.5432', '', 'W HK, N H2', '5.J83.5432.T987', 'H8'],
      'discourage without one' => ['4S', 'N', '432.765.T98.5432', '', 'W HK, N H2', '5.983.5432.T987', 'H3'],
      'count: high from an even number' => ['4S', 'N', '432.KQ2.KQ5.6543', '', 'N H4, E H6, S HQ', '5.85.J7432.T987', 'H8'],
      'count: low from an odd number' => ['4S', 'N', '432.KQ2.KQ5.6543', '', 'N H4, E H6, S HQ', '5.853.J743.T987', 'H3'],
      'cover an honour led from dummy' => ['4S', 'N', '432.QT3.Q65.5432', 'E C2, S C3, W C4, N CA', 'S HQ', '5.K84.J743.T987', 'HK'],
      'no cover when dummy leads from a sequence' => ['4S', 'N', '432.QJ3.Q65.5432', 'E C2, S C3, W C4, N CA', 'S HQ', '5.K84.J743.T987', 'H4'],
      'third hand only as high as dummy needs' => ['3NT', 'N', '432.T3.Q65.5432', '', 'W H2, N H4', 'K5.KJ8.J743.T987', 'HJ'],
      'take the setting trick' => ['4S', 'N', '432.K3.Q63.7543', 'W CA, N C2, E C4, S C3; W CK, N C5, E C6, S C7; W DA, N D2, E D5, S D3', 'N H3', '5.A84.J74.T98', 'HA'],
    ];
  }

  #[DataProvider('defence')]
  public function test_defence(string $contract, string $declarer, string $dummy, string $before, string $trick, string $hand, string $card): void
  {
    $strain = substr($contract, 1);
    $trick = $this->trick($trick);
    $turn = Seats::next(end($trick)['seat']);
    $tricks = $before === '' ? $this->placeholderTricks(1) : $this->tricks($strain, $before);
    $state = $this->state($strain, $declarer, $turn, $this->cards($hand), $this->cards($dummy), $trick, $tricks, (int) $contract[0]);

    $this->assertSame($this->card($card)['id'], RobotCardPlayer::choose($state));
  }

  /**
   * A defender (E, declarer N in 4♠) on lead: the tricks so far, the hand,
   * dummy's, the lead.
   */
  public static function defenderLeads(): array
  {
    return [
      'continue when partner encouraged' => ['E HK, S H2, W H9, N HA; N CQ, E CA, S C4, W C2', '54.KJ43.954.AT98', 'KQJ7.T82.QJT8.54', 'H3'],
      'switch when partner discouraged' => ['E HK, S H2, W H3, N HA; N CQ, E CA, S C4, W C2', '54.KJ654.95.A986', 'KQJ7.T82.QJT8.54', 'C9'],
      'give partner a ruff, asking for diamonds' => ['E HA, S H2, W H3, N H4; E HK, S H5, W D3, N H6', '54.AKQ87.AJ5.T98', 'KQJ7.T952.QT8.76', 'H8'],
      'give partner a ruff, asking for clubs' => ['E HA, S H2, W H3, N H4; E HK, S H5, W D3, N H6', '54.AKQ87.J65.AT9', 'KQJ7.T952.QT8.76', 'H7'],
      'return the suit partner asked for after a ruff' => ['E DK, S D4, W DT, N DA; N D8, E D5, S D6, W D9; W H2, N H3, E S4, S H4', 'T984.-.KJ532.J87', 'KQJ7.T94.64.652', 'C7'],
      'lead the suit partner discarded high in' => ['E H2, S H3, W HA, N H4; W HK, N H5, E H6, S H7; W C2, N CA, E C3, S C4; N SA, E S2, S S3, W S4; N CK, E C5, S C6, W D9; N H8, E HQ, S H9, W HT', 'T7652.Q62.K42.53', 'KQJ3.973.QJT.864', 'D2'],
    ];
  }

  #[DataProvider('defenderLeads')]
  public function test_defender_on_lead(string $tricks, string $hand, string $dummy, string $lead): void
  {
    $state = $this->state('S', 'N', 'E', $this->cards($hand), $this->cards($dummy), [], $this->tricks('S', $tricks), 4);

    $this->assertSame($this->card($lead)['id'], RobotCardPlayer::choose($state));
  }

  /**
   * A defender on lead seeing dummy: contract, declarer, the leader, the
   * tricks so far, the leader's hand, dummy's, the leads allowed.
   */
  public static function defenceSeesDummy(): array
  {
    // 2♣ by S: dummy N is out of hearts with one trump left (♣2), holds
    // ♦A-Q-J and a weak ♠J-7-4; E leads with dummy on its right
    $twoClubs = 'W HK, N H6, E H2, S H3; W HA, N C3, E H4, S H5; N S2, E SA, S S3, W S5';

    return [
      'not into dummy\'s ruff or tenace: a trump or dummy\'s weak suit' => ['2C', 'S', 'E', $twoClubs, 'A86.J942.T98.T95', 'J742.6.AQJ654.32', ['S8', 'C5']],
      'no master cashed in a suit dummy ruffs' => ['2C', 'S', 'E', $twoClubs, 'A86.Q942.T98.T95', 'J742.6.AQJ654.32', ['S8', 'C5']],
      // 4♠ by S: dummy and declarer are both out of hearts, dummy holds
      // trumps; E has nothing but hearts and trumps
      'no ruff-and-discard: a trump instead' => ['4S', 'S', 'E', 'W HK, N S2, E H3, S H4; N D2, E DA, S D3, W D4; E H5, S C3, W H6, N S3; N D5, E DK, S D7, W D8', '9654.QJT9853.AK.-', 'Q732.-.Q952.J8642', ['S4']],
      // 6♠ by S, out of spades and hearts like dummy: partner E is out
      // of hearts and holds the trumps above dummy's J, and dummy plays
      // before partner, who over-ruffs for the setting trick
      'a ruff-and-discard for partner to beat the contract' => ['6S', 'S', 'W', 'W D2, N D3, E D4, S DA; S C2, W C3, N CA, E C4; N SA, E S3, S C5, W S4; N HA, E D5, S C6, W H3; N D6, E D7, S D8, W DQ', '4.KQJT93.QJ2.T73', 'AJ2.A.9763.AKQ98', ['H9']],
      'no ruff-and-discard with the contract not at stake' => ['5S', 'S', 'W', 'W D2, N D3, E D4, S DA; S C2, W C3, N CA, E C4; N SA, E S3, S C5, W S4; N HA, E D5, S C6, W H3; N D6, E D7, S D8, W DQ', '4.KQJT93.QJ2.T73', 'AJ2.A.9763.AKQ98', ['DJ', 'C7', 'CT']],
      // 4♥ by S: dummy is out of spades and keeps its trumps to ruff them
      'a trump to cut dummy\'s ruffs' => ['4H', 'S', 'E', 'W DQ, N DA, E C3, S D3; N C2, E CA, S C5, W C4', 'KT973.962.-.AJ983', '-.J843.AK65.KQ762', ['H2']],
      // 4♠ by N: partner encouraged E's hearts, but dummy is out of them now
      'no continuing a suit dummy ruffs' => ['4S', 'N', 'E', 'E HK, S H2, W H9, N S7; N CQ, E CA, S C6, W C2', '54.KJ43.954.AT98', 'KQJ73.-.QJT8.Q543', ['CT']],
      // 3NT by S: dummy N holds ♦A-Q-5; W has it on its left, E on its right
      'through dummy\'s tenace' => ['3NT', 'S', 'W', 'W HA, N H2, E H3, S H4', '-.A.9876432.87654', 'A532.K62.AQ5.KQ2', ['D6']],
      'not up to dummy\'s tenace' => ['3NT', 'S', 'E', 'W H5, N H2, E HA, S H4', '-.A.9876432.87654', 'A532.K62.AQ5.KQ2', ['C5']],
    ];
  }

  /**
   * @param  list<string>  $leads
   */
  #[DataProvider('defenceSeesDummy')]
  public function test_a_defender_on_lead_sees_dummy(string $contract, string $declarer, string $leader, string $tricks, string $hand, string $dummy, array $leads): void
  {
    $strain = substr($contract, 1);
    $state = $this->state($strain, $declarer, $leader, $this->cards($hand), $this->cards($dummy), [], $this->tricks($strain, $tricks), (int) $contract[0]);
    $ids = array_map(fn ($lead) => $this->card($lead)['id'], $leads);

    $this->assertContains(RobotCardPlayer::choose($state), $ids);
  }

  public function test_hold_up_the_ace_against_dummys_long_suit_by_partners_count(): void
  {
    // 3NT by S; dummy N holds K-Q-J-T-4 of diamonds and no other winner; E
    // holds A-7-3 behind it. Partner W plays the 2, showing an odd number:
    // three of the five out, so S has two and E takes the second round.
    $dummy = $this->cards('543.543.KQJT4.54');
    $hand = $this->cards('K76.K76.A73.J762');

    $state = $this->state('NT', 'S', 'E', $hand, $dummy, $this->trick('S D5, W D2, N DK'), [], 3);
    $this->assertSame($this->card('D3')['id'], RobotCardPlayer::choose($state), 'ducks the first round');

    $state = $this->state('NT', 'S', 'E', $hand, $dummy, $this->trick('S D6, W D8, N DQ'), $this->tricks('NT', 'S D5, W D2, N DK, E D3'), 3);
    $this->assertSame($this->card('DA')['id'], RobotCardPlayer::choose($state), 'takes the round S plays its last');

    // partner's 8 first: an even number, two of five, so S has three
    $state = $this->state('NT', 'S', 'E', $hand, $dummy, $this->trick('S D6, W D2, N DQ'), $this->tricks('NT', 'S D5, W D8, N DK, E D3'), 3);
    $this->assertSame($this->card('D7')['id'], RobotCardPlayer::choose($state), 'ducks again: S still has one');
  }

  /**
   * A defender (W, declarer S in 4♥) throwing a card on a spade it can't
   * follow: its hand, dummy's, the card.
   */
  public static function discards(): array
  {
    return [
      'not from a guarded king' => ['-.-.K2.T98765', '432.543.8765.AQ', 'C5'],
      'not below dummy\'s length' => ['-.-.987.9876', '5432.54.J.AK54', 'D7'],
      'from the longest spare suit' => ['-.-.87654.J9876', '432.543.AK.A5432', 'D4'],
    ];
  }

  #[DataProvider('discards')]
  public function test_discards(string $hand, string $dummy, string $card): void
  {
    $state = $this->state('H', 'S', 'W', $this->cards($hand), $this->cards($dummy), $this->trick('S SA'), $this->placeholderTricks(1), 4);

    $this->assertSame($this->card($card)['id'], RobotCardPlayer::choose($state));
  }

  public function test_the_ending_is_searched(): void
  {
    // 1NT by S, two tricks left, S on lead with ♠A ♣2 and E known to have
    // no spades: whoever holds what, leading the club first lets the
    // defenders take both tricks
    $played = $this->tricks('NT', implode('; ', [
      'W S2, N S3, E H2, S S4', 'S S5, W S6, N S7, E H3', 'S S8, W S9, N ST, E H4',
      'N H5, E H6, S H7, W H8', 'W H9, N HT, E HJ, S HQ', 'S HK, W HA, N D2, E D3',
      'W D4, N D5, E D6, S D7', 'S D8, W D9, N DT, E DJ', 'E DQ, S DK, W DA, N C3',
      'W C4, N C5, E C6, S C7', 'S C8, W C9, N SJ, E CT',
    ]));

    $view = PlayView::fromState($this->state('NT', 'S', 'S', $this->cards('A.-.-.2'), $this->cards('-.-.-.QJ'), [], $played, 1));

    $this->assertSame($this->card('SA'), Endgame::choose($view, $this->card('C2')));
    $this->assertSame($this->card('SA'), Endgame::choose($view, $this->card('SA')));
  }

  /**
   * Four robots play out eighty random deals in random contracts:
   * every card is legal by the real rules, and all 13 tricks get played.
   */
  public function test_every_card_is_legal(): void
  {
    for ($board = 0; $board < 80; $board++) {
      $hands = $this->deal();
      $declarer = Seats::SEATS[$board % 4];
      $dummy = Seats::partner($declarer);
      $strain = ['C', 'D', 'H', 'S', 'NT'][mt_rand(0, 4)];
      $trump = $strain === 'NT' ? null : $strain;
      $plays = [];

      for ($count = 0; $count < 52; $count++) {
        $turn = CardPlayService::nextToPlay($plays, $declarer, $trump);
        $acting = CardPlayService::actingSeat($turn, $declarer);

        $tricks = [];

        foreach (array_chunk(array_slice($plays, 0, intdiv($count, 4) * 4), 4) as $trick) {
          $tricks[] = ['cards' => $this->payload($trick)];
        }

        $state = [
          'my_seat' => $acting,
          'turn' => $turn,
          'contract' => ['declarer' => $declarer, 'bid' => ['level' => mt_rand(1, 7), 'strain' => $strain]],
          'hand' => $hands[$acting],
          'dummy_hand' => $plays === [] ? null : $hands[$dummy],
          'tricks' => $tricks,
          'current_trick' => $this->payload(CardPlayService::currentTrick($plays)),
        ];

        $id = RobotCardPlayer::choose($state);
        $card = collect($hands[$turn])->firstWhere('id', $id);

        $this->assertNotNull($card, "card $id is not in $turn's hand");
        $this->assertNull(CardPlayService::illegalReason($plays, $hands[$turn], $this->model($card)));

        $plays[] = ['seat' => $turn, 'card' => $this->model($card)];
        $hands[$turn] = array_values(array_filter($hands[$turn], fn ($held) => $held['id'] !== $id));
      }

      $this->assertNull(CardPlayService::nextToPlay($plays, $declarer, $trump));
    }
  }

  /**
   * @param  list<array<string, mixed>>  $trick
   * @param  list<array<string, mixed>>  $tricks
   * @return array<string, mixed>
   */
  private function state(
    string $strain,
    string $declarer,
    string $turn,
    array $hand,
    ?array $dummy,
    array $trick = [],
    array $tricks = [],
    int $level = 1
  ): array {
    // the hands are written as dealt: what has been played is gone
    $played = array_column([...array_merge([], ...array_column($tricks, 'cards')), ...$trick], 'card');

    $ids = array_column([...$hand, ...($dummy ?? [])], 'id');
    $this->assertSame(array_unique($ids), $ids, 'a card is dealt to both hands');
    $this->assertSame(array_unique(array_column($played, 'id')), array_column($played, 'id'), 'a card is played twice');
    $left = fn (?array $cards) => $cards === null ? null : array_values(array_filter($cards, fn ($card) => ! in_array($card, $played, true)));

    return [
      'my_seat' => CardPlayService::actingSeat($turn, $declarer),
      'turn' => $turn,
      'contract' => ['declarer' => $declarer, 'bid' => ['level' => $level, 'strain' => $strain]],
      'hand' => $left($hand),
      'dummy_hand' => $left($dummy),
      'tricks' => $tricks,
      'current_trick' => $trick,
    ];
  }

  /**
   * A trick written `'W D2, N D3'`, lead first, in the payload shape.
   *
   * @return list<array{seat: string, card: array{id: int, suit: string, rank: int}}>
   */
  private function trick(string $trick): array
  {
    return array_map(function ($play) {
      [$seat, $card] = explode(' ', $play);

      return ['seat' => $seat, 'card' => $this->card($card)];
    }, explode(', ', $trick));
  }

  /**
   * Complete tricks written `'W HQ, N H4, E H7, S HA; …'`, each with its
   * leader and winner.
   *
   * @return list<array{leader: string, cards: list<array<string, mixed>>, winner: string}>
   */
  private function tricks(string $strain, string $tricks): array
  {
    return array_map(function ($trick) use ($strain) {
      $cards = $this->trick(trim($trick));

      return [
        'leader' => $cards[0]['seat'],
        'cards' => $cards,
        'winner' => PlayView::winning($cards, $strain === 'NT' ? null : $strain)['seat'],
      ];
    }, explode(';', $tricks));
  }

  /**
   * Finished tricks of cards no hand in these tests holds, won by nobody.
   *
   * @return list<array{cards: list<array<string, mixed>>}>
   */
  private function placeholderTricks(int $count): array
  {
    return array_fill(0, $count, ['cards' => []]);
  }

  /**
   * @param  list<array{seat: string, card: \App\Models\Card}>  $plays
   * @return list<array{seat: string, card: array{id: int, suit: string, rank: int}}>
   */
  private function payload(array $plays): array
  {
    return array_map(fn ($play) => [
      'seat' => $play['seat'],
      'card' => ['id' => (int) $play['card']->id, 'suit' => $play['card']->suit, 'rank' => (int) $play['card']->rank],
    ], $plays);
  }
}

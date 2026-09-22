<?php

namespace Tests\Unit;

use App\Models\Card;
use App\Services\CardPlayService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules of play on their own, over in-memory cards: no database.
 */
class CardPlayServiceTest extends TestCase
{
  private const RANKS = ['J' => 12, 'Q' => 13, 'K' => 14, 'A' => 15];

  /**
   * trick written `'N SA, E S2, ...'` (lead first), trump, winning seat
   */
  public static function tricks(): array
  {
    return [
      'highest of the suit led' => ['N S9, E SK, S S2, W SQ', 'H', 'E'],
      'a higher card of another suit does not count' => ['N S9, E HA, S S2, W DA', 'C', 'N'],
      'ten loses to jack across the gap at 11' => ['N S10, E SJ, S S2, W S3', null, 'E'],
      'a trump beats the suit led' => ['N SA, E H2, S SK, W SQ', 'H', 'E'],
      'the highest of several trumps' => ['N SA, E H2, S HJ, W H10', 'H', 'S'],
      'a trump lead is just the suit led' => ['N H5, E H9, S SA, W H2', 'H', 'E'],
      'nothing is trump in NT' => ['N S2, E HA, S DA, W CA', null, 'N'],
    ];
  }

  #[DataProvider('tricks')]
  public function test_the_trick_winner(string $trick, ?string $trump, string $winner): void
  {
    $this->assertSame($winner, CardPlayService::trickWinner($this->plays($trick), $trump));
  }

  public function test_declarers_left_hand_opponent_leads_then_clockwise(): void
  {
    $this->assertSame('E', CardPlayService::nextToPlay([], 'N', 'H'));
    $this->assertSame('N', CardPlayService::nextToPlay([], 'W', 'H'));
    $this->assertSame('S', CardPlayService::nextToPlay($this->plays('E S2'), 'N', 'H'));
    $this->assertSame('N', CardPlayService::nextToPlay($this->plays('E S2, S S3, W S4'), 'N', 'H'));
  }

  public function test_the_winner_leads_the_next_trick(): void
  {
    $plays = $this->plays('E S2, S S3, W SA, N S4');

    $this->assertSame('W', CardPlayService::nextToPlay($plays, 'N', 'H'));
  }

  public function test_nobody_plays_after_thirteen_tricks(): void
  {
    $plays = array_fill(0, 52, ['seat' => 'N', 'card' => $this->card('S2')]);

    $this->assertNull(CardPlayService::nextToPlay($plays, 'N', 'H'));
  }

  public function test_declarer_acts_for_dummy(): void
  {
    $this->assertSame('N', CardPlayService::actingSeat('S', 'N'));
    $this->assertSame('N', CardPlayService::actingSeat('N', 'N'));
    $this->assertSame('E', CardPlayService::actingSeat('E', 'N'));
    $this->assertNull(CardPlayService::actingSeat(null, 'N'));
  }

  public function test_the_lead_may_be_any_card_in_the_hand(): void
  {
    $this->assertNull(CardPlayService::illegalReason([], $this->hand('SA H2'), $this->card('H2')));
  }

  public function test_a_hand_holding_the_suit_led_must_follow(): void
  {
    $plays = $this->plays('E S2');

    $this->assertSame(
      'You must follow suit: Spades were led.',
      CardPlayService::illegalReason($plays, $this->hand('SA H2'), $this->card('H2'))
    );
    $this->assertNull(CardPlayService::illegalReason($plays, $this->hand('SA H2'), $this->card('SA')));
  }

  public function test_a_hand_out_of_the_suit_led_may_play_anything(): void
  {
    $this->assertNull(CardPlayService::illegalReason($this->plays('E S2'), $this->hand('D3 H2'), $this->card('H2')));
  }

  public function test_only_the_first_card_of_the_open_trick_sets_the_suit(): void
  {
    // hearts led the first trick; spades lead the second
    $plays = $this->plays('E H3, S H4, W H5, N H6, N S2');

    $this->assertSame(
      'You must follow suit: Spades were led.',
      CardPlayService::illegalReason($plays, $this->hand('SA H2'), $this->card('H2'))
    );
  }

  public function test_a_card_is_played_once(): void
  {
    $this->assertSame(
      'That card has already been played.',
      CardPlayService::illegalReason($this->plays('E S2'), $this->hand('SA'), $this->card('S2'))
    );
  }

  public function test_the_card_must_be_in_the_hand(): void
  {
    $this->assertSame(
      'That card is not in the hand being played.',
      CardPlayService::illegalReason([], $this->hand('SA'), $this->card('SK'))
    );
  }

  public function test_tricks_are_counted_by_side(): void
  {
    $plays = $this->plays('E S2, S S3, W SA, N S4, W H2, N HA, E H3, S H4, N D2');

    $this->assertSame(['ns' => 1, 'ew' => 1], CardPlayService::tricksWon($plays, null));
    $this->assertCount(2, CardPlayService::tricks($plays, null));
    $this->assertCount(1, CardPlayService::currentTrick($plays));
    $this->assertSame([], CardPlayService::currentTrick(array_slice($plays, 0, 8)));
  }

  /**
   * Plays written `'N SA, E H10'`: seat, then suit and rank.
   *
   * @return list<array{seat: string, card: Card}>
   */
  private function plays(string $plays): array
  {
    return array_map(function ($play) {
      [$seat, $card] = explode(' ', $play);

      return ['seat' => $seat, 'card' => $this->card($card)];
    }, explode(', ', $plays));
  }

  /**
   * A hand written `'SA H2'`, shaped like `PlayingStateService::hand()`.
   *
   * @return list<array{id: int, suit: string, rank: int}>
   */
  private function hand(string $cards): array
  {
    return array_map(function ($code) {
      $card = $this->card($code);

      return ['id' => $card->id, 'suit' => $card->suit, 'rank' => $card->rank];
    }, explode(' ', $cards));
  }

  /**
   * A card from its code (`SA`, `H10`), with an id unique to suit and rank.
   */
  private function card(string $code): Card
  {
    $suit = $code[0];
    $rank = self::RANKS[substr($code, 1)] ?? (int) substr($code, 1);

    $card = new Card(['suit' => $suit, 'rank' => $rank]);
    $card->id = array_search($suit, ['C', 'D', 'H', 'S'], true) * 100 + $rank;

    return $card;
  }
}

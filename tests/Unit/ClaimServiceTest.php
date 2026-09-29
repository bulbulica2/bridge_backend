<?php

namespace Tests\Unit;

use App\Models\Card;
use App\Services\ClaimService;
use App\Services\PlayingStateService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The claim rules on their own, over in-memory cards: no database.
 */
class ClaimServiceTest extends TestCase
{
  private const RANKS = ['J' => 12, 'Q' => 13, 'K' => 14, 'A' => 15];

  public function test_only_declarer_and_the_defenders_may_claim_and_only_in_the_play(): void
  {
    foreach (['N', 'E', 'W'] as $seat) {
      $this->assertNull(ClaimService::illegalPlayerReason(PlayingStateService::PHASE_PLAY, $seat, 'N'));
    }

    $this->assertSame(
      "Dummy takes no part in a claim: declarer claims for declarer's side.",
      ClaimService::illegalPlayerReason(PlayingStateService::PHASE_PLAY, 'S', 'N')
    );
    $this->assertSame(
      'You are not playing this board.',
      ClaimService::illegalPlayerReason(PlayingStateService::PHASE_PLAY, null, 'N')
    );
    $this->assertSame('The auction is not over yet.', ClaimService::illegalPlayerReason(PlayingStateService::PHASE_AUCTION, 'N', null));
    $this->assertSame('The board is finished.', ClaimService::illegalPlayerReason(PlayingStateService::PHASE_FINISHED, 'N', 'N'));
    $this->assertStringStartsWith('The table has no board yet', ClaimService::illegalPlayerReason(PlayingStateService::PHASE_WAITING, null, null));
  }

  /**
   * claimer, declarer, the seats that must accept
   */
  public static function responders(): array
  {
    return [
      "declarer's claim needs both defenders" => ['N', 'N', ['E', 'W']],
      "an E-W declarer's claim needs both defenders" => ['W', 'W', ['N', 'S']],
      "a defender's claim needs declarer and the other defender" => ['E', 'N', ['N', 'W']],
      'the other defender likewise' => ['W', 'N', ['N', 'E']],
      'against an E-W declarer' => ['S', 'E', ['N', 'E']],
    ];
  }

  #[DataProvider('responders')]
  public function test_who_must_accept(string $claimer, string $declarer, array $responders): void
  {
    $this->assertSame($responders, ClaimService::responders($claimer, $declarer));
  }

  public function test_dummy_never_answers(): void
  {
    foreach (['N', 'E', 'W'] as $claimer) {
      $this->assertNotContains('S', ClaimService::responders($claimer, 'N'));
    }
  }

  public function test_a_trick_in_progress_still_counts_as_remaining(): void
  {
    $this->assertSame(13, ClaimService::remaining([]));
    $this->assertSame(13, ClaimService::remaining($this->plays('E S2, S S3')));
    $this->assertSame(12, ClaimService::remaining($this->plays('E S2, S S3, W SA, N S4')));
    $this->assertSame(12, ClaimService::remaining($this->plays('E S2, S S3, W SA, N S4, W H2')));
  }

  public function test_the_claim_is_between_0_and_the_remaining_tricks(): void
  {
    $this->assertNull(ClaimService::tricksReason(0, 13));
    $this->assertNull(ClaimService::tricksReason(13, 13));
    $this->assertNull(ClaimService::tricksReason(5, 5));

    $this->assertSame('You can claim between 0 and 5 tricks: 5 remain to be played.', ClaimService::tricksReason(6, 5));
    $this->assertSame('You can claim between 0 and 13 tricks: 13 remain to be played.', ClaimService::tricksReason(-1, 13));
  }

  public function test_declarers_claim_adds_to_declarers_tricks(): void
  {
    // N-S won the first trick, E-W the second: 11 remain
    $plays = $this->plays('E S2, S S3, W S4, N SA, N H2, E HA, S H3, W H4');

    $this->assertSame(1 + 11, ClaimService::declarerTricks($plays, null, 'N', 'N', 11));
    $this->assertSame(1 + 7, ClaimService::declarerTricks($plays, null, 'N', 'N', 7));
  }

  public function test_a_defenders_claim_leaves_declarer_the_rest(): void
  {
    $plays = $this->plays('E S2, S S3, W S4, N SA, N H2, E HA, S H3, W H4');

    $this->assertSame(1 + 11 - 3, ClaimService::declarerTricks($plays, null, 'N', 'E', 3));
    $this->assertSame(1 + 11 - 3, ClaimService::declarerTricks($plays, null, 'N', 'W', 3));
  }

  public function test_a_concession_gives_the_other_side_everything(): void
  {
    $plays = $this->plays('E S2, S S3, W S4, N SA');

    // declarer concedes the remaining 12
    $this->assertSame(1, ClaimService::declarerTricks($plays, null, 'N', 'N', 0));
    // a defender concedes them to declarer
    $this->assertSame(13, ClaimService::declarerTricks($plays, null, 'N', 'W', 0));
  }

  public function test_an_east_west_declarer_counts_east_wests_tricks(): void
  {
    // N-S won the only trick, E-W declares
    $plays = $this->plays('S S2, W S3, N SA, E S4');

    $this->assertSame(0 + 10, ClaimService::declarerTricks($plays, null, 'E', 'E', 10));
    $this->assertSame(0 + 12 - 2, ClaimService::declarerTricks($plays, null, 'E', 'N', 2));
  }

  public function test_a_claim_mid_trick_covers_the_trick_in_progress(): void
  {
    // one trick to N-S, then two cards of the second: 12 remain, the open trick among them
    $plays = $this->plays('E S2, S S3, W S4, N SA, N H2, E HA');

    $this->assertSame(1 + 12, ClaimService::declarerTricks($plays, null, 'N', 'N', 12));
    $this->assertSame(1 + 12 - 1, ClaimService::declarerTricks($plays, null, 'N', 'E', 1));
  }

  public function test_the_trump_decides_the_tricks_won_so_far(): void
  {
    // E ruffs the spade in hearts
    $plays = $this->plays('W S2, N SA, E H2, S S3');

    $this->assertSame(0 + 12, ClaimService::declarerTricks($plays, 'H', 'S', 'S', 12));
    $this->assertSame(1 + 12, ClaimService::declarerTricks($plays, null, 'S', 'S', 12));
  }

  /**
   * Plays written `'N SA, E H10'`: seat, then suit and rank.
   *
   * @return list<array{seat: string, card: Card}>
   */
  private function plays(string $plays): array
  {
    return array_map(function ($play) {
      [$seat, $code] = explode(' ', $play);

      $suit = $code[0];
      $rank = self::RANKS[substr($code, 1)] ?? (int) substr($code, 1);

      $card = new Card(['suit' => $suit, 'rank' => $rank]);
      $card->id = array_search($suit, ['C', 'D', 'H', 'S'], true) * 100 + $rank;

      return ['seat' => $seat, 'card' => $card];
    }, explode(', ', $plays));
  }
}

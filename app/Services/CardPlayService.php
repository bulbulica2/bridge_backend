<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\auxiliary\Suits;
use App\Events\PlayingUpdated;
use App\Exceptions\IllegalPlayException;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\Table;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The play: takes one card at a time, checks it against the rules of play
 * (`GAME-RULES.md` §5), decides each trick's winner and, after the 13th
 * trick, scores the board and closes the playing.
 *
 * Like `AuctionService`, the rules are static functions over a list of plays,
 * each a `['seat' => 'N', 'card' => Card]` pair in the order they were played
 * (`PlayingStateService::plays()`), so they are unit-tested with no database.
 * A trick is four consecutive plays; the first one of each is its lead.
 */
class CardPlayService
{
  public const TRICKS = 13;

  public function __construct(private PlayingStateService $state) {}

  /**
   * Play `$card` for `$user` at the table's current playing: from their own
   * hand, or from dummy's when it is dummy's turn and they are declarer.
   *
   * The playing's row is locked first, so two cards played at once queue and
   * the second is checked against the first.
   *
   * @throws IllegalPlayException
   */
  public function play(Table $table, User $user, Card $card): void
  {
    DB::transaction(function () use ($table, $user, $card) {
      $playing = $this->state->currentPlaying($table, lock: true);

      $phaseError = match ($this->state->phase($playing)) {
        PlayingStateService::PHASE_WAITING => 'The table has no board yet: the play starts once four players are seated and the auction is over.',
        PlayingStateService::PHASE_AUCTION => 'The auction is not over yet.',
        PlayingStateService::PHASE_FINISHED => 'The board is finished.',
        default => null,
      };

      if ($phaseError !== null) {
        throw new IllegalPlayException($phaseError);
      }

      $seat = $this->state->seatOf($playing, $user);

      if ($seat === null) {
        throw new IllegalPlayException('You are not playing this board.');
      }

      $declarer = $playing->declarer_seat;
      $dummy = Seats::partner($declarer);

      if ($seat === $dummy) {
        throw new IllegalPlayException("Dummy doesn't play: declarer plays dummy's cards.");
      }

      $plays = $this->state->plays($playing);
      $turn = self::nextToPlay($plays, $declarer, $this->state->trump($playing));

      if ($seat !== self::actingSeat($turn, $declarer)) {
        throw new IllegalPlayException("It is not your turn: $turn plays next.");
      }

      $reason = self::illegalReason($plays, $this->state->hand($playing, $turn), $card);

      if ($reason !== null) {
        throw new IllegalPlayException($reason);
      }

      $count = count($plays);
      $round = intdiv($count, 4) + 1;

      $playing->cardPlays()->create([
        'user_id' => $user->id,
        'card_id' => $card->id,
        'seat' => $turn,
        'round' => $round,
        'order' => $count % 4 + 1,
      ]);

      $plays[] = ['seat' => $turn, 'card' => $card];

      if (count($plays) % 4 === 0) {
        $winner = self::trickWinner(array_slice($plays, -4), $this->state->trump($playing));

        $playing->cardPlays()
          ->where('round', $round)
          ->where('seat', $winner)
          ->update(['won_trick' => true]);
      }

      if (count($plays) === self::TRICKS * 4) {
        $this->saveResult($playing, $plays);
      }

      PlayingUpdated::dispatch($table);
    });
  }

  /**
   * Close the playing after the 13th trick: declarer's tricks, the score
   * and `finished_at` (`BoardTable::finish()`).
   *
   * @param  list<array{seat: string, card: Card}>  $plays
   */
  private function saveResult(BoardTable $playing, array $plays): void
  {
    $won = self::tricksWon($plays, $this->state->trump($playing));

    $playing->finish($won[self::side($playing->declarer_seat)]);
  }

  /**
   * The seat whose card is played next: declarer's left-hand opponent leads
   * the first trick, each trick goes clockwise, and the winner of a trick
   * leads the next one. Null once all 13 tricks are played.
   *
   * @param  list<array{seat: string, card: Card}>  $plays
   */
  public static function nextToPlay(array $plays, string $declarer, ?string $trump): ?string
  {
    $count = count($plays);

    return match (true) {
      $count >= self::TRICKS * 4 => null,
      $count === 0 => Seats::next($declarer),
      $count % 4 === 0 => self::trickWinner(array_slice($plays, -4), $trump),
      default => Seats::next(end($plays)['seat']),
    };
  }

  /**
   * The seat of the player who acts for `$turn`: its own player, except
   * dummy's hand, which declarer plays.
   */
  public static function actingSeat(?string $turn, string $declarer): ?string
  {
    return $turn === Seats::partner($declarer) ? $declarer : $turn;
  }

  /**
   * Why `$card` may not be played now from a hand holding `$hand`, or null
   * when it may. Whose turn it is is checked separately (`nextToPlay()`).
   *
   * @param  list<array{seat: string, card: Card}>  $plays
   * @param  list<array{id: int, suit: string}>  $hand  the cards the seat still holds
   */
  public static function illegalReason(array $plays, array $hand, Card $card): ?string
  {
    if (count($plays) >= self::TRICKS * 4) {
      return 'All 13 tricks have been played.';
    }

    foreach ($plays as $play) {
      if ((int) $play['card']->id === (int) $card->id) {
        return 'That card has already been played.';
      }
    }

    if (! in_array((int) $card->id, array_map(fn ($held) => (int) $held['id'], $hand), true)) {
      return 'That card is not in the hand being played.';
    }

    $trick = self::currentTrick($plays);

    if ($trick === []) {
      return null;
    }

    $led = $trick[0]['card']->suit;

    if ($card->suit !== $led && in_array($led, array_column($hand, 'suit'), true)) {
      return 'You must follow suit: '.Suits::SUIT_NAME[$led].' were led.';
    }

    return null;
  }

  /**
   * The seat that won a complete trick: the highest trump in it, or with no
   * trump the highest card of the suit led. `$trump` is null in NT. Ranks
   * are compared, never assumed contiguous (they skip 11).
   *
   * @param  list<array{seat: string, card: Card}>  $trick  the four plays, lead first
   */
  public static function trickWinner(array $trick, ?string $trump): string
  {
    $best = $trick[0];

    foreach (array_slice($trick, 1) as $play) {
      $card = $play['card'];
      $beats = $card->suit === $best['card']->suit
        ? (int) $card->rank > (int) $best['card']->rank
        : $card->suit === $trump;

      if ($beats) {
        $best = $play;
      }
    }

    return $best['seat'];
  }

  /**
   * The plays of the trick in progress: `[]` before the opening lead and
   * between a trick's fourth card and the next lead.
   *
   * @param  list<array{seat: string, card: Card}>  $plays
   * @return list<array{seat: string, card: Card}>
   */
  public static function currentTrick(array $plays): array
  {
    return array_slice($plays, intdiv(count($plays), 4) * 4);
  }

  /**
   * The complete tricks so far, each with its leader and winner.
   *
   * @param  list<array{seat: string, card: Card}>  $plays
   * @return list<array{round: int, leader: string, plays: list<array{seat: string, card: Card}>, winner: string}>
   */
  public static function tricks(array $plays, ?string $trump): array
  {
    $tricks = [];

    foreach (array_chunk($plays, 4) as $i => $trick) {
      if (count($trick) < 4) {
        break;
      }

      $tricks[] = [
        'round' => $i + 1,
        'leader' => $trick[0]['seat'],
        'plays' => $trick,
        'winner' => self::trickWinner($trick, $trump),
      ];
    }

    return $tricks;
  }

  /**
   * Tricks won by each side so far.
   *
   * @param  list<array{seat: string, card: Card}>  $plays
   * @return array{ns: int, ew: int}
   */
  public static function tricksWon(array $plays, ?string $trump): array
  {
    $won = ['ns' => 0, 'ew' => 0];

    foreach (self::tricks($plays, $trump) as $trick) {
      $won[self::side($trick['winner'])]++;
    }

    return $won;
  }

  /**
   * `ns` or `ew`: the partnership a seat plays for.
   */
  public static function side(string $seat): string
  {
    return in_array($seat, ['N', 'S'], true) ? 'ns' : 'ew';
  }
}

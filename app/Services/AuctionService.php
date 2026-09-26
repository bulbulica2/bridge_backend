<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\Events\PlayingUpdated;
use App\Exceptions\IllegalCallException;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The auction: takes one call at a time, checks it against the bidding rules
 * (`GAME-RULES.md` §4) and, once the auction is over, saves the contract and
 * declarer on the playing.
 *
 * The rules themselves are static functions over a list of calls, each a
 * `['seat' => 'N', 'bid' => Bid]` pair in the order they were made, so they
 * work the same on the stored auction (`PlayingStateService::calls()`) and in
 * unit tests with no database.
 */
class AuctionService
{
  public function __construct(private PlayingStateService $state) {}

  /**
   * Make `$user`'s call at the table's current playing.
   *
   * The playing's row is locked first, so two players calling at once queue
   * behind each other and the second sees the first one's call when it works
   * out whose turn it is.
   *
   * @throws IllegalCallException
   */
  public function call(Table $table, User $user, Bid $bid): void
  {
    DB::transaction(function () use ($table, $user, $bid) {
      $playing = $this->state->currentPlaying($table, lock: true);

      if ($playing === null) {
        throw new IllegalCallException('The table has no board yet: the auction starts once four players are seated.');
      }

      if ($this->state->phase($playing) !== PlayingStateService::PHASE_AUCTION) {
        throw new IllegalCallException('The auction has ended.');
      }

      $seat = $this->state->seatOf($playing, $user);

      if ($seat === null) {
        throw new IllegalCallException('You are not playing this board.');
      }

      $calls = $this->state->calls($playing);
      $turn = self::nextToCall($calls, $playing->board->dealer);

      if ($seat !== $turn) {
        throw new IllegalCallException("It is not your turn: $turn calls next.");
      }

      $reason = self::illegalReason($calls, $seat, $bid);

      if ($reason !== null) {
        throw new IllegalCallException($reason);
      }

      $playing->auctions()->create([
        'user_id' => $user->id,
        'bid_id' => $bid->id,
        'seat' => $seat,
      ]);

      $calls[] = ['seat' => $seat, 'bid' => $bid];

      if (self::isOver($calls)) {
        $this->saveResult($playing, $calls);
      }

      PlayingUpdated::dispatch($table);
    });
  }

  /**
   * Write the outcome of a finished auction on the playing. A passed out
   * board has no contract; it scores 0, so it is finished at once.
   *
   * @param  list<array{seat: string, bid: Bid}>  $calls
   */
  private function saveResult(BoardTable $playing, array $calls): void
  {
    $result = self::result($calls);

    if ($result === null) {
      $playing->update(['auction_ended_at' => now()]);
      $playing->finish(null);

      return;
    }

    $playing->update([
      'contract_bid_id' => $result['bid']->id,
      'doubled' => $result['doubled'],
      'declarer_seat' => $result['declarer'],
      'declarer_id' => $playing->seats->firstWhere('seat', $result['declarer'])?->user_id,
      'auction_ended_at' => now(),
    ]);
  }

  /**
   * The seat to call next: the dealer first, then clockwise. Null once the
   * auction is over.
   *
   * @param  list<array{seat: string, bid: Bid}>  $calls
   */
  public static function nextToCall(array $calls, string $dealer): ?string
  {
    if (self::isOver($calls)) {
      return null;
    }

    return $calls === [] ? $dealer : Seats::next(end($calls)['seat']);
  }

  /**
   * Why `$seat` may not make this call now, or null when it may. Turn order
   * is checked separately (`nextToCall()`).
   *
   * @param  list<array{seat: string, bid: Bid}>  $calls
   */
  public static function illegalReason(array $calls, string $seat, Bid $bid): ?string
  {
    if (self::isOver($calls)) {
      return 'The auction has ended.';
    }

    if ($bid->isPass()) {
      return null;
    }

    if ($bid->isContract()) {
      $lastBid = self::lastContract($calls);

      if ($lastBid !== null && ! $bid->isHigherThan($lastBid['bid'])) {
        return "{$bid->suit} is not higher than the last bid, {$lastBid['bid']->suit}.";
      }

      return null;
    }

    // X and XX act on the last call that isn't a pass; passes in between
    // don't matter
    $last = self::lastNonPass($calls);

    if ($bid->isDouble()) {
      return match (true) {
        $last === null => 'There is no bid to double.',
        $last['bid']->isDouble() => 'That bid is already doubled.',
        $last['bid']->isRedouble() => 'That bid is already redoubled.',
        self::sameSide($last['seat'], $seat) => "You can't double your own side's bid.",
        default => null,
      };
    }

    if ($bid->isRedouble()) {
      return match (true) {
        $last !== null && $last['bid']->isRedouble() => 'That bid is already redoubled.',
        $last === null || ! $last['bid']->isDouble() => 'Only a double can be redoubled.',
        self::sameSide($last['seat'], $seat) => "You can't redouble your own side's double.",
        default => null,
      };
    }

    return "Unknown call '{$bid->suit}'.";
  }

  /**
   * Three passes after a bid, X or XX, or four passes from the start.
   *
   * @param  list<array{seat: string, bid: Bid}>  $calls
   */
  public static function isOver(array $calls): bool
  {
    if (count($calls) < 4) {
      return false;
    }

    foreach (array_slice($calls, -3) as $call) {
      if (! $call['bid']->isPass()) {
        return false;
      }
    }

    return true;
  }

  /**
   * The contract a finished auction reached: the last bid, whether it was
   * doubled (1) or redoubled (2) since, and the declarer — the player of the
   * side that won the contract who named its strain first. Null when the
   * board was passed out.
   *
   * @param  list<array{seat: string, bid: Bid}>  $calls
   * @return array{bid: Bid, doubled: int, declarer: string}|null
   */
  public static function result(array $calls): ?array
  {
    $last = self::lastContract($calls);

    if ($last === null) {
      return null;
    }

    // X/XX only count after the final bid: a new bid cancels them
    $doubled = 0;

    foreach (array_slice($calls, $last['index'] + 1) as $call) {
      if ($call['bid']->isRedouble()) {
        $doubled = 2;
      } elseif ($call['bid']->isDouble()) {
        $doubled = max($doubled, 1);
      }
    }

    $declarer = null;

    foreach ($calls as $call) {
      if ($call['bid']->isContract()
        && $call['bid']->strain === $last['bid']->strain
        && self::sameSide($call['seat'], $last['seat'])) {
        $declarer = $call['seat'];

        break;
      }
    }

    return [
      'bid' => $last['bid'],
      'doubled' => $doubled,
      'declarer' => $declarer,
    ];
  }

  /**
   * @param  list<array{seat: string, bid: Bid}>  $calls
   * @return array{seat: string, bid: Bid, index: int}|null
   */
  private static function lastContract(array $calls): ?array
  {
    for ($i = count($calls) - 1; $i >= 0; $i--) {
      if ($calls[$i]['bid']->isContract()) {
        return [...$calls[$i], 'index' => $i];
      }
    }

    return null;
  }

  /**
   * @param  list<array{seat: string, bid: Bid}>  $calls
   * @return array{seat: string, bid: Bid}|null
   */
  private static function lastNonPass(array $calls): ?array
  {
    for ($i = count($calls) - 1; $i >= 0; $i--) {
      if (! $calls[$i]['bid']->isPass()) {
        return $calls[$i];
      }
    }

    return null;
  }

  private static function sameSide(string $a, string $b): bool
  {
    return $a === $b || Seats::partner($a) === $b;
  }
}

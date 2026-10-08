<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\Http\Resources\PlayingResource;
use App\Models\Bid;
use App\Models\Board;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The read side of a playing: which phase it is in, whose turn it is, and
 * what one player may see of it.
 *
 * Everything that works out game state lives here, so the auction, the card
 * play and scoring each extend these methods rather than compute it again.
 */
class PlayingStateService
{
  public const PHASE_WAITING = 'waiting';

  public const PHASE_AUCTION = 'auction';

  public const PHASE_PLAY = 'play';

  public const PHASE_FINISHED = 'finished';

  /** `turn_deadline_by`: the turn clock (`bridge.turn_seconds`) ends the turn. */
  public const DEADLINE_BY_MOVE = 'move';

  /** `turn_deadline_by`: the player's time bank for the set ends it first. */
  public const DEADLINE_BY_SET = 'set';

  /** `turn_deadline_by`: the player is away, and their seat's `replace_at` ends it. */
  public const DEADLINE_BY_AWAY = 'away';

  /**
   * Suits in the order a hand is shown: spades first, alternating colours.
   */
  public const HAND_SUIT_ORDER = ['S', 'H', 'D', 'C'];

  /**
   * What `PlayingResource` reads from a playing, loaded up front.
   */
  public const RELATIONS = ['board', 'tableSet.seats', 'seats.user', 'auctions.bid', 'contractBid', 'cardPlays.card'];

  /**
   * The playing of the board the table is on now, or null while it has none
   * (fewer than four players).
   *
   * `$lock` takes the row lock a write needs (inside a transaction). A
   * playing detached by a player leaving has lost its `table_id`, so it is
   * not found here even if `$table` was loaded before that happened.
   */
  public function currentPlaying(Table $table, bool $lock = false): ?BoardTable
  {
    if ($table->board_id === null) {
      return null;
    }

    return BoardTable::query()
      ->where('table_id', $table->getKey())
      ->where('board_id', $table->board_id)
      ->when($lock, fn ($query) => $query->lockForUpdate())
      ->with(self::RELATIONS)
      ->first();
  }

  public function phase(?BoardTable $playing): string
  {
    return match (true) {
      $playing === null => self::PHASE_WAITING,
      $playing->auction_ended_at === null => self::PHASE_AUCTION,
      $playing->finished_at === null => self::PHASE_PLAY,
      default => self::PHASE_FINISHED,
    };
  }

  /**
   * The seat expected to act next, or null when nobody is.
   *
   * During the auction that is the dealer, then clockwise after the last
   * call. During the play it is the hand the next card comes from � dummy's
   * included, though declarer is the one who plays it (`actingUserId()`).
   */
  public function turn(?BoardTable $playing): ?string
  {
    return match ($this->phase($playing)) {
      self::PHASE_AUCTION => AuctionService::nextToCall($this->calls($playing), $playing->board->dealer),
      self::PHASE_PLAY => CardPlayService::nextToPlay($this->plays($playing), $playing->declarer_seat, $this->trump($playing)),
      default => null,
    };
  }

  /**
   * The user who acts for `turn()`: that seat's player, except that
   * declarer plays dummy's cards — and a human dummy plays both hands for a
   * robot declarer (`dummyPlaysForDeclarer()`).
   */
  public function actingUserId(?BoardTable $playing): ?int
  {
    $turn = $this->turn($playing);

    if ($turn === null) {
      return null;
    }

    if ($this->phase($playing) === self::PHASE_PLAY) {
      $turn = CardPlayService::actingSeat($turn, $playing->declarer_seat, $this->dummyPlaysForDeclarer($playing));
    }

    $userId = $playing->seats->firstWhere('seat', $turn)?->user_id;

    return $userId === null ? null : (int) $userId;
  }

  /**
   * When the turn of the player the board waits for runs out: the earlier of
   * their turn clock, `bridge.turn_seconds` after the board began waiting
   * for them (`turn_started_at`: the deal, the last call or card, a claim
   * made or cleared), and the end of their time bank for the set
   * (`TableSetSeat::time_left_ms`, as of `turn_started_at`). Being there
   * isn't playing: only a move resets it, never a heartbeat or a chat line.
   *
   * A player away (`table_seats.away_since`) has no turn clock: the board
   * waits for them until their seat's `replace_at`, or the end of their
   * time bank if that comes first.
   *
   * Only a human who is not an admin has a clock (`clockedUser()`), only in
   * the auction and the play of a set's board, and not while a claim is
   * pending (it expires by itself). Null whenever nobody's clock runs. Once
   * it has passed, `tables:check-away` takes them out and a robot plays
   * their seat for the rest of the set (`TableSeatService::checkAway()`).
   */
  public function turnDeadline(?BoardTable $playing): ?Carbon
  {
    return $this->turnClock($playing)['deadline'] ?? null;
  }

  /**
   * `turnDeadline()` and what sets it: `by` is DEADLINE_BY_SET when the
   * player's time bank for the set runs out first (or with the other
   * clock), DEADLINE_BY_AWAY when they are away and their seat's
   * `replace_at` comes first, DEADLINE_BY_MOVE otherwise. Null whenever
   * nobody's clock runs.
   *
   * @return array{deadline: Carbon, by: string}|null
   */
  public function turnClock(?BoardTable $playing): ?array
  {
    $user = $this->clockedUser($playing);

    if ($user === null) {
      return null;
    }

    $bank = $playing->tableSet->seats->firstWhere('user_id', $user->id)?->time_left_ms;
    $bankEnd = $bank === null ? null : $playing->turn_started_at->copy()->addSeconds(intdiv($bank, 1000));

    $replaceAt = TableSeat::query()
      ->where('table_id', $playing->table_id)
      ->where('user_id', $user->id)
      ->first(['replace_at'])
      ?->replace_at;

    // away: the table waits for them as long as their seat is kept
    $other = $replaceAt !== null
      ? ['deadline' => $replaceAt, 'by' => self::DEADLINE_BY_AWAY]
      : ['deadline' => $playing->turn_started_at->copy()->addSeconds((int) config('bridge.turn_seconds')), 'by' => self::DEADLINE_BY_MOVE];

    if ($bankEnd !== null && $bankEnd->lte($other['deadline'])) {
      return ['deadline' => $bankEnd, 'by' => self::DEADLINE_BY_SET];
    }

    return $other;
  }

  /**
   * The player whose turn clock and time bank run now: the human, not an
   * admin, who acts for the board (`actingUserId()`: declarer on dummy's
   * turn, a robot declarer's human dummy on declarer's), in the auction or
   * the play of a set still going on, with no claim pending. Null whenever
   * nobody's runs: in `waiting`, between boards, during a claim, for a
   * robot or an admin.
   */
  public function clockedUser(?BoardTable $playing): ?User
  {
    if ($playing?->turn_started_at === null
      || $playing->tableSet === null
      || $playing->tableSet->isFinished()
      || $playing->hasPendingClaim()) {
      return null;
    }

    // nobody acts in `waiting` or once the board is finished
    $actingUserId = $this->actingUserId($playing);
    $user = $actingUserId === null ? null : $playing->seats->firstWhere('user_id', $actingUserId)?->user;

    return $user === null || $user->is_robot || $user->is_admin ? null : $user;
  }

  /**
   * Whether dummy plays declarer's cards as well as their own: when a robot
   * declares and its partner, dummy, is a human, who came to play rather
   * than watch. Declarer and dummy stay where the auction put them; only
   * who sends the cards (and claims for that side) changes. From the
   * snapshot taken at the deal; false while there is no contract.
   */
  public function dummyPlaysForDeclarer(BoardTable $playing): bool
  {
    if ($playing->declarer_seat === null) {
      return false;
    }

    $declarer = $playing->seats->firstWhere('seat', $playing->declarer_seat)?->user;
    $dummy = $playing->seats->firstWhere('seat', Seats::partner($playing->declarer_seat))?->user;

    return $declarer?->is_robot === true && $dummy !== null && ! $dummy->is_robot;
  }

  /**
   * Declarer's remaining cards for the human dummy who plays them
   * (`dummyPlaysForDeclarer()`), from the end of the auction to the end of
   * the play; null for anyone else and at any other time. Private to that
   * player: never in the public state.
   *
   * @return list<array{id: int, suit: string, rank: int, rank_name: string}>|null
   */
  public function declarerHandFor(BoardTable $playing, ?string $seat): ?array
  {
    if ($seat === null
      || $this->phase($playing) !== self::PHASE_PLAY
      || $seat !== Seats::partner($playing->declarer_seat)
      || ! $this->dummyPlaysForDeclarer($playing)) {
      return null;
    }

    return $this->hand($playing, $playing->declarer_seat);
  }

  /**
   * The cards played so far, in the order they were played (trick, then
   * position in it), as the list `CardPlayService`'s rules read.
   *
   * @return list<array{seat: string, card: Card}>
   */
  public function plays(BoardTable $playing): array
  {
    return $playing->cardPlays
      ->sortBy([['round', 'asc'], ['order', 'asc']])
      ->map(fn ($play) => ['seat' => $play->seat, 'card' => $play->card])
      ->values()
      ->all();
  }

  /**
   * The contract's trump suit, or null in NT and when there is no contract.
   */
  public function trump(BoardTable $playing): ?string
  {
    $strain = $playing->contractBid?->strain;

    return $strain === 'NT' ? null : $strain;
  }

  /**
   * Dummy's remaining cards, face up for everyone once the opening lead is
   * made; null before that and when there is no contract.
   *
   * @return list<array{id: int, suit: string, rank: int, rank_name: string}>|null
   */
  public function dummyHand(BoardTable $playing): ?array
  {
    if ($playing->declarer_seat === null || $playing->cardPlays->isEmpty()) {
      return null;
    }

    return $this->hand($playing, Seats::partner($playing->declarer_seat));
  }

  /**
   * The calls made so far, in the order they were made (row id), as the
   * list `AuctionService`'s rules read.
   *
   * @return list<array{seat: string, bid: Bid}>
   */
  public function calls(BoardTable $playing): array
  {
    return $playing->auctions
      ->sortBy('id')
      ->map(fn ($auction) => ['seat' => $auction->seat, 'bid' => $auction->bid])
      ->values()
      ->all();
  }

  /**
   * What `$seat`'s player may see of each call's alert, in `calls()`'s
   * order: `alert` (`{explanation}`, null when the call isn't alerted) and
   * `question` (`{asked_by}`, the opponent whose question about it is still
   * open) for their own calls and the opponents', but null for partner's
   * during the auction: seeing those would be unauthorised information.
   * Once the auction is over every alert is open to the four players, while
   * partner's questions stay hidden; once the board is finished every alert
   * is public, to anyone (`$seat` null too), and no question is open any
   * more. A kibitzer (`$watching`, `$seat` null) sees that a call is
   * alerted, but not what it means until the board is finished, nor any
   * question.
   *
   * @return list<array{alert: array{explanation: string|null}|null, question: array{asked_by: string}|null}>
   */
  public function alerts(BoardTable $playing, ?string $seat, bool $watching = false): array
  {
    $finished = $playing->finished_at !== null;
    $ended = $playing->auction_ended_at !== null;

    return $playing->auctions
      ->sortBy('id')
      ->map(function ($call) use ($seat, $finished, $ended, $watching) {
        $notPartners = $seat !== null && $call->seat !== Seats::partner($seat);
        $sees = $finished || $notPartners || ($ended && $seat !== null);

        return [
          'alert' => match (true) {
            ! $call->alerted => null,
            $sees => ['explanation' => $call->explanation],
            $watching => ['explanation' => null],
            default => null,
          },
          'question' => $notPartners && ! $finished && $call->question_seat !== null ? ['asked_by' => $call->question_seat] : null,
        ];
      })
      ->values()
      ->all();
  }

  /**
   * The seat a user held when this board was dealt, from the snapshot.
   */
  public function seatOf(BoardTable $playing, User $user): ?string
  {
    return $playing->seats->firstWhere('user_id', $user->id)?->seat;
  }

  /**
   * The cards a seat still holds: dealt to it and not yet played, spades to
   * clubs, high to low.
   *
   * @return list<array{id: int, suit: string, rank: int, rank_name: string}>
   */
  public function hand(BoardTable $playing, string $seat): array
  {
    return self::sorted(
      $playing->board->cards()
        ->wherePivot('seat', $seat)
        ->whereNotIn('cards.id', $playing->cardPlays()->select('card_id'))
        ->get()
    );
  }

  /**
   * All four hands as they were dealt (`board_card`, not what is left after
   * the play), each sorted like `hand()`. Only for a finished board: before
   * that, three of them are hidden from each player.
   *
   * @return array<string, list<array{id: int, suit: string, rank: int, rank_name: string}>>
   */
  public function deal(BoardTable $playing): array
  {
    return $this->boardDeal($playing->board);
  }

  /**
   * A board's four hands as dealt, like `deal()`. Show it only to someone
   * who has finished the board (`BoardPolicy::view`).
   *
   * @return array<string, list<array{id: int, suit: string, rank: int, rank_name: string}>>
   */
  public function boardDeal(Board $board): array
  {
    $cards = $board->cards()->get();
    $deal = [];

    foreach (Seats::SEATS as $seat) {
      $deal[$seat] = self::sorted($cards->filter(fn ($card) => $card->pivot->seat === $seat));
    }

    return $deal;
  }

  /**
   * The seats whose players have asked for the next board, in seat order.
   *
   * @return list<string>
   */
  public function ready(BoardTable $playing): array
  {
    $ready = $playing->seats->whereNotNull('ready_at')->pluck('seat')->all();

    return array_values(array_intersect(Seats::SEATS, $ready));
  }

  /**
   * When the set's next board is dealt by itself (`DealNextBoard`):
   * `bridge.next_board_seconds` after `$playing` finished. Null while it
   * isn't finished, and whenever no automatic deal is coming: the set is
   * over (its last board, or abandoned), the playing has left its table, or
   * the four seated now aren't the set's four — a seat is empty, or
   * somebody left and somebody else sat down, and then the next board takes
   * everyone's Start.
   */
  public function nextBoardAt(BoardTable $playing): ?Carbon
  {
    if ($playing->finished_at === null
      || $playing->tableSet === null
      || $playing->tableSet->isFinished()
      || ! $this->seatedAsIn($playing)) {
      return null;
    }

    return $playing->finished_at->copy()->addSeconds((int) config('bridge.next_board_seconds'));
  }

  /**
   * Whether the four seated at `$playing`'s table now are the four of its
   * set (who played it, or the robot that took a seat over from one of them
   * since: `TableSetSeat::replaced_user_id`), each in the same seat; with no
   * set, the four who played it. Never true of a playing that has left its
   * table.
   */
  public function seatedAsIn(BoardTable $playing): bool
  {
    $now = TableSeat::query()->where('table_id', $playing->table_id)->pluck('user_id', 'seat')->sortKeys()->all();
    $then = ($playing->tableSet?->seats() ?? $playing->seats())->pluck('user_id', 'seat')->sortKeys()->all();

    return $playing->table_id !== null && count($now) === count(Seats::SEATS) && $now == $then;
  }

  /**
   * Cards spades to clubs, high to low, in the payload's card shape.
   *
   * @param  iterable<Card>  $cards
   * @return list<array{id: int, suit: string, rank: int, rank_name: string}>
   */
  private static function sorted(iterable $cards): array
  {
    $suitOrder = array_flip(self::HAND_SUIT_ORDER);

    return collect($cards)
      ->sort(fn ($a, $b) => [$suitOrder[$a->suit], -$a->rank] <=> [$suitOrder[$b->suit], -$b->rank])
      ->map(fn ($card) => self::card($card))
      ->values()
      ->all();
  }

  /**
   * One card as every payload shows it.
   *
   * @return array{id: int, suit: string, rank: int, rank_name: string}
   */
  public static function card(Card $card): array
  {
    return [
      'id' => (int) $card->id,
      'suit' => $card->suit,
      'rank' => (int) $card->rank,
      'rank_name' => (string) $card->rank_name,
    ];
  }

  /**
   * What every player at the table may see, as JSON-ready arrays: the shape
   * `PlayingUpdated` broadcasts.
   *
   * @return array<string, mixed>
   */
  public function publicState(Table $table): array
  {
    // through JSON, as an HTTP response would be: resolve() leaves the nested
    // user resources as objects, still holding the models
    return json_decode(json_encode(new PlayingResource($this->currentPlaying($table))), true);
  }

  /**
   * The public state plus what only this user may see: their seat and hand,
   * the alerts made to them and their own (`alerts()`, on each `auction`
   * entry), and `declarer_hand` for a human dummy who plays a robot
   * declarer's cards. Dummy's hand isn't private once it is face up, so it
   * is in the public part (`dummy_hand`), for declarer and everyone else
   * alike.
   *
   * @return array<string, mixed>
   */
  public function stateFor(Table $table, User $user): array
  {
    return $this->stateOf($this->currentPlaying($table), $user);
  }

  /**
   * `stateFor()`, or null while the table has no playing: what a request that
   * may have dealt the board (the last Start) hands back with the
   * table, so the client can draw it without a `GET /tables/{table}/playing`.
   *
   * @return array<string, mixed>|null
   */
  public function dealtStateFor(Table $table, User $user): ?array
  {
    $playing = $this->currentPlaying($table);

    return $playing === null ? null : $this->stateOf($playing, $user);
  }

  /**
   * What a kibitzer sees (`TablePolicy::watch`, not seated): the public
   * state in `stateFor()`'s shape with no seat and no hand, and each call's
   * `alert` with no explanation until the board is finished (`alerts()`).
   * Never the caller's own hand, even one they held on this board before
   * they lost their seat.
   *
   * @return array<string, mixed>
   */
  public function watcherStateFor(Table $table): array
  {
    return $this->stateOf($this->currentPlaying($table), null);
  }

  /**
   * `$user`'s state, or a kibitzer's with `$user` null.
   *
   * @return array<string, mixed>
   */
  private function stateOf(?BoardTable $playing, ?User $user): array
  {
    $seat = $playing === null || $user === null ? null : $this->seatOf($playing, $user);
    $public = json_decode(json_encode(new PlayingResource($playing)), true);

    if ($playing !== null) {
      $alerts = $this->alerts($playing, $seat, watching: $user === null);

      foreach ($public['auction'] as $index => $call) {
        $public['auction'][$index] = [...$call, ...$alerts[$index]];
      }
    }

    return [
      ...$public,
      'my_seat' => $seat,
      'hand' => $seat === null ? null : $this->hand($playing, $seat),
      'declarer_hand' => $playing === null ? null : $this->declarerHandFor($playing, $seat),
    ];
  }
}

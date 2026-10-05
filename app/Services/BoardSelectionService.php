<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\auxiliary\Vulnerability;
use App\Events\HandDealt;
use App\Events\PlayingUpdated;
use App\Events\TableUpdated;
use App\Exceptions\NextBoardException;
use App\Exceptions\StartBoardException;
use App\Models\Board;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\TableSet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Picks the board a table plays next and opens the `board_table` record for
 * it.
 *
 * A playing can only start once all four seats are taken: the selection rule
 * needs every player's history, and `board_table_seats` snapshots four seats.
 * Filling the table isn't enough, though: every human seated there has to
 * press Start too (`start()`, `table_seats.ready_at`), so nobody is thrown
 * into an auction before they have reached the table. Robots are ready from
 * the moment they sit down. After that, a finished board is followed by the
 * next one by itself, `bridge.next_board_seconds` later (`dealNext()`), or
 * at once when every human of the same four asks for it (`moveOn()`).
 *
 * Boards come in sets (`TableSet`, `bridge.set_size` boards): everyone's
 * Start opens a set with its first board, the rest follow as above, and
 * after the last one Next is refused and it takes everyone's Start again to
 * open the next set. A set one of its four players leaves is over too
 * (`abandonSet()`), and one a player lets their turn clock run out on, or
 * walks out on, is lost by their side (`forfeitSet()`).
 */
class BoardSelectionService
{
  public function __construct(private PlayingStateService $state) {}

  /**
   * `$user` presses Start: they are ready to play. The board is dealt once
   * the table is full and every seat is ready (`startIfReady()`), so a human
   * alone with three robots deals it with their one Start, and one pressed
   * before the table is full is kept until the fourth seat is taken.
   *
   * Pressing twice changes nothing. Returns the new playing once it is
   * dealt, or null while the table is short or somebody has still to press.
   *
   * Broadcasts `TableUpdated` when the caller is newly ready, along with
   * `deal()`'s own events when that deals.
   *
   * Mutates `$table` (`board_id`).
   *
   * @throws StartBoardException
   */
  public function start(Table $table, User $user): ?BoardTable
  {
    return DB::transaction(function () use ($table, $user) {
      $seat = $this->startableSeat($table, $user);

      if ($seat->ready_at !== null) {
        return null;
      }

      $seat->update(['ready_at' => now()]);

      $playing = $this->startIfReady($table);

      TableUpdated::dispatch($table);

      return $playing;
    });
  }

  /**
   * `$user` takes their Start back, while the board isn't dealt yet. Does
   * nothing if they hadn't pressed it; otherwise broadcasts `TableUpdated`.
   *
   * @throws StartBoardException
   */
  public function withdrawStart(Table $table, User $user): void
  {
    DB::transaction(function () use ($table, $user) {
      $seat = $this->startableSeat($table, $user);

      if ($seat->ready_at === null) {
        return;
      }

      $seat->update(['ready_at' => null]);

      TableUpdated::dispatch($table);
    });
  }

  /**
   * Deal the table a board if it is full and every seat is ready, and open
   * the playing. Called by `start()` and whenever somebody sits down
   * (`TableSeatService::seat()`): a robot taking the fourth seat after every
   * human has pressed Start deals at once. A table only robots are keeping
   * is never dealt to.
   *
   * Returns null when nothing was dealt. Mutates `$table` (`board_id`).
   */
  public function startIfReady(Table $table): ?BoardTable
  {
    $seats = $table->seats()->with('user')->get();

    if ($seats->count() < count(Seats::SEATS)
      || $seats->every(fn ($seat) => $seat->user->is_robot)
      || $seats->contains(fn ($seat) => $seat->ready_at === null)) {
      return null;
    }

    return $this->deal($table, $seats);
  }

  /**
   * Deal a board to a full table, and open the playing: the next board of
   * `$set`, or with no set (a Start) the first board of a new one. Clears
   * the humans' Start: the next time the table needs one (a new set, or a
   * player left and was replaced) everyone presses it again.
   *
   * Returns null when a playing is already open. Mutates `$table`
   * (`board_id`).
   *
   * @param  Collection<int, TableSeat>  $seats  all four, with their users
   */
  private function deal(Table $table, Collection $seats, ?TableSet $set = null): ?BoardTable
  {
    if ($this->openPlaying($table) !== null) {
      return null;
    }

    $set ??= $this->openSet($table, $seats);

    $board = $this->selectBoard($table, $seats);

    $table->update(['board_id' => $board->id]);
    $table->unsetRelation('latestSet');

    $playing = BoardTable::create([
      'board_id' => $board->id,
      'table_id' => $table->id,
      'table_set_id' => $set->id,
      'set_position' => $set->playings()->reorder()->count() + 1,
      'started_at' => now(),
      // the dealer's turn clock, if they have one
      'turn_started_at' => now(),
    ]);

    foreach ($seats as $seat) {
      $playing->seats()->create([
        'user_id' => $seat->user_id,
        'seat' => $seat->seat,
      ]);
    }

    // the double dummy table depends only on the deal: solved once, in the
    // queue, the first time the board is dealt
    app(DoubleDummyService::class)->queueTable($board);

    // a Start deals one board; robots stay ready
    $table->seats()->whereHas('user', fn ($user) => $user->humans())->update(['ready_at' => null]);

    // everyone sees the board; each player alone gets their cards
    PlayingUpdated::dispatch($table);

    // a robot reads its hand from the state when it moves: nobody listens
    // on its channel
    foreach ($seats->reject(fn ($seat) => $seat->user->is_robot) as $seat) {
      HandDealt::dispatch($playing, $seat->user_id, $seat->seat);
    }

    return $playing;
  }

  /**
   * Open the table's next set for the four seated now, numbered on from the
   * last one played here, `bridge.set_size` boards long.
   *
   * @param  Collection<int, TableSeat>  $seats  all four
   */
  private function openSet(Table $table, Collection $seats): TableSet
  {
    // a Start only comes once the last set is over, so this is a guard
    $this->abandonSet($table);

    $set = TableSet::create([
      'table_id' => $table->id,
      'number' => (int) TableSet::where('table_id', $table->id)->max('number') + 1,
      'size' => max(1, (int) config('bridge.set_size')),
      'started_at' => now(),
    ]);

    foreach ($seats as $seat) {
      $set->seats()->create(['user_id' => $seat->user_id, 'seat' => $seat->seat]);
    }

    return $set;
  }

  /**
   * End the table's set early, because one of its four players has left:
   * the set is `abandoned` and has no winner. The board on the table is
   * dealt with by `abandonPlaying()` as before; a set is never resumed, so
   * the next board waits for everyone's Start and opens a new set.
   *
   * Does nothing once the set is over.
   */
  public function abandonSet(Table $table): void
  {
    TableSet::query()
      ->where('table_id', $table->id)
      ->whereNull('finished_at')
      ->each(fn (TableSet $set) => $set->end(TableSet::ENDED_ABANDONED));

    $table->unsetRelation('latestSet');
  }

  /**
   * End the table's set as lost by `$side` (`NS` or `EW`) for `$reason`
   * (one of `TableSet::FORFEIT_REASONS`): one of its players let their turn
   * clock run out, walked out on it for another table, or was kicked while
   * away (`TableSeatService::remove()`). The board on the table goes as on
   * any leave (`abandonPlaying()`), unscored.
   *
   * Does nothing once the set is over.
   */
  public function forfeitSet(Table $table, string $side, string $reason): void
  {
    TableSet::query()
      ->where('table_id', $table->id)
      ->whereNull('finished_at')
      ->each(fn (TableSet $set) => $set->forfeit($side, $reason));

    $table->unsetRelation('latestSet');
  }

  /**
   * The table's set while it is going on — a board of it in progress, or
   * between its boards — or null outside a set. Its four players are the
   * four seated: anyone leaving ends it.
   */
  public function currentSet(Table $table): ?TableSet
  {
    return TableSet::query()
      ->where('table_id', $table->id)
      ->whereNull('finished_at')
      ->first();
  }

  /**
   * Whether the set `$playing` was dealt in is over (a playing made outside
   * the services has none, and counts as over): nothing follows it but a
   * Start.
   */
  private function setOver(BoardTable $playing): bool
  {
    return $playing->tableSet === null || $playing->tableSet->isFinished();
  }

  /**
   * The caller's seat, once the table is locked, if Start means anything
   * now: no board, a finished one that ended its set, or a finished one
   * that the four seated now didn't all play (somebody left and was
   * replaced). With a board in its auction or play, or finished mid-set with
   * the same four still there (who go on with `moveOn()`), it is refused.
   *
   * @throws StartBoardException
   */
  private function startableSeat(Table $table, User $user): TableSeat
  {
    // the lock seat changes take, so a player leaving and another pressing
    // Start queue rather than race; then reread the board
    Table::whereKey($table->getKey())->lockForUpdate()->firstOrFail();
    $table->refresh();

    $seat = $table->seats()->where('user_id', $user->id)->first();

    if ($seat === null) {
      throw new StartBoardException('You are not seated at this table.');
    }

    $playing = $this->state->currentPlaying($table, lock: true);

    $refusal = match ($this->state->phase($playing)) {
      PlayingStateService::PHASE_WAITING => null,
      PlayingStateService::PHASE_FINISHED => $this->state->seatedAsIn($playing) && ! $this->setOver($playing)
        ? 'The board is finished: the next board of the set is dealt by itself shortly (POST /tables/{table}/playing/next deals it at once).'
        : null,
      default => 'A board is already in progress at this table.',
    };

    if ($refusal !== null) {
      throw new StartBoardException($refusal);
    }

    return $seat;
  }

  /**
   * `$user` asks for the table's next board once the current one is
   * finished, rather than wait for it to come by itself (`dealNext()`).
   * The result stays up until every human has asked, so nobody has it
   * pulled away before they have read it; robots count as having asked
   * (they ask anyway, as soon as the board ends). The last human to ask
   * deals the next board, picked by the same rule as the first (`deal()`),
   * so a human alone with three robots skips the wait.
   * Each player asks for themselves; nobody asks for anyone else. Only the
   * four who played the board go on this way: once one of them has been
   * replaced, the table needs every human's Start again.
   * Next only deals within a set: after its last board it is refused, and
   * the next set takes everyone's Start.
   *
   * Asking twice changes nothing. Returns the new playing once it is dealt,
   * or null while a human has still to ask.
   *
   * Broadcasts `PlayingUpdated` when a player is newly ready and, once the
   * board is dealt, `TableUpdated` along with `deal()`'s own events — what
   * the last Start sends.
   *
   * Mutates `$table` (`board_id`).
   *
   * @throws NextBoardException
   */
  public function moveOn(Table $table, User $user): ?BoardTable
  {
    return DB::transaction(function () use ($table, $user) {
      // the lock seat changes take, so a player leaving and the last player
      // asking queue rather than race; then reread the board, which whoever
      // held the lock before may have moved on
      Table::whereKey($table->getKey())->lockForUpdate()->firstOrFail();
      $table->refresh();

      $playing = $this->state->currentPlaying($table, lock: true);

      $phaseError = match ($this->state->phase($playing)) {
        PlayingStateService::PHASE_WAITING => 'The table has no board yet: the first one is dealt once four players are seated and have pressed Start.',
        PlayingStateService::PHASE_FINISHED => null,
        default => 'The board is not finished yet.',
      };

      if ($phaseError !== null) {
        throw new NextBoardException($phaseError);
      }

      if ($table->seats()->count() < count(Seats::SEATS)) {
        throw new NextBoardException('The table is short of a player: the next board is dealt once a fourth one sits down and every player has pressed Start.');
      }

      // a player who left was replaced: the newcomer never saw this board,
      // so it is everyone's Start that deals the next one
      if (! $this->state->seatedAsIn($playing)) {
        throw new NextBoardException('The players have changed since this board: the next one is dealt once every player has pressed Start.');
      }

      // the set's last board: the result stays up, and a new set takes
      // everyone's Start
      if ($this->setOver($playing)) {
        throw new NextBoardException('The set is over: press Start for a new one.');
      }

      $asking = $playing->seats()->whereNull('ready_at')->where('user_id', $user->id);

      if ($asking->update(['ready_at' => now()]) === 0) {
        return null;
      }

      if ($playing->seats()->whereNull('ready_at')->whereHas('user', fn ($user) => $user->humans())->exists()) {
        PlayingUpdated::dispatch($table);

        return null;
      }

      $next = $this->deal($table, $table->seats()->with('user')->get(), $playing->tableSet);

      TableUpdated::dispatch($table);

      return $next;
    });
  }

  /**
   * Deal the set's next board by itself once finished playing `$playingId`
   * has been on show for `bridge.next_board_seconds` (`nextBoardAt()`), for
   * the same four, as the last human's Next would (`moveOn()`), with the
   * same events. Away players are dealt to as well: once the board waits
   * for them, their turn clock runs as anyone's does.
   *
   * Deals nothing when, by now, the table has moved on (everyone asked, or
   * this ran twice), the playing has left its table, a seat is empty or has
   * changed hands, or the set is over (its last board, or a forfeit) —
   * or the time hasn't come yet. Run by `DealNextBoard`.
   *
   * Returns the new playing, or null when nothing was dealt. Mutates
   * nothing passed in.
   */
  public function dealNext(int $playingId): ?BoardTable
  {
    return DB::transaction(function () use ($playingId) {
      // the lock moveOn() and seat changes take, so the last Next, a
      // player leaving and this queue rather than race
      $table = Table::query()
        ->whereIn('id', BoardTable::query()->whereKey($playingId)->select('table_id'))
        ->lockForUpdate()
        ->first();

      $playing = $table === null ? null : $this->state->currentPlaying($table, lock: true);

      if ($playing?->id !== $playingId) {
        return null;
      }

      $at = $this->state->nextBoardAt($playing);

      if ($at === null || now()->isBefore($at)) {
        return null;
      }

      $next = $this->deal($table, $table->seats()->with('user')->get(), $playing->tableSet);

      TableUpdated::dispatch($table);

      return $next;
    });
  }

  /**
   * Cut a table loose from a playing it started but never finished, because
   * the four who started it are no longer the four sitting there.
   *
   * The row and its seat snapshot are kept, not deleted: those four players
   * were dealt these hands and have seen them, and the selection rule has to
   * go on knowing that or it would deal them the same cards again. Detaching
   * is what `board_table.table_id` being nullable is already for — it is how
   * a playing survives its table being deleted — and it frees the table from
   * `unique(board_id, table_id)` so it can be dealt a fresh board.
   *
   * Its calls and cards are deleted, though: the board is never resumed and
   * has no result to review. Only a finished playing keeps them, for
   * `GET /playings/{playing}`.
   *
   * A finished playing is the duplicate result and is never touched.
   *
   * Mutates `$table` (`board_id`).
   */
  public function abandonPlaying(Table $table): void
  {
    $playing = $this->openPlaying($table);

    if ($playing === null) {
      return;
    }

    $playing->discardLogs();
    $playing->update(['table_id' => null]);

    $table->update(['board_id' => null]);
  }

  /**
   * Shuffle a fresh deal. Board numbers carry the dealer and vulnerability
   * cycles, so they continue from the highest one already stored.
   */
  public function dealBoard(): Board
  {
    $cardIds = Card::pluck('id')->all();

    if (count($cardIds) !== 52) {
      throw new RuntimeException(
        'Cannot deal a board: the cards table holds '.count($cardIds).' rows, expected 52. Run CardSeeder.'
      );
    }

    shuffle($cardIds);

    $number = (int) (Board::max('number') ?? 0) + 1;

    $board = Board::create([
      'number' => $number,
      'dealer' => Seats::dealerForBoard($number),
      'vulnerable' => Vulnerability::forBoard($number),
    ]);

    $hands = [];

    foreach (array_chunk($cardIds, 13) as $index => $hand) {
      foreach ($hand as $cardId) {
        $hands[] = [
          'board_id' => $board->id,
          'card_id' => $cardId,
          'seat' => Seats::SEATS[$index],
        ];
      }
    }

    DB::table('board_card')->insert($hands);

    return $board;
  }

  /**
   * The board-selection rule from `docs/GAME-RULES.md` §8: boards
   * should rotate as much as possible, so players never recognise a deal.
   *
   * Robots' history doesn't count: they don't remember deals, and a busy
   * robot pool would soon have played every board.
   *
   * @param  Collection<int, \App\Models\TableSeat>  $seats  with their users
   */
  private function selectBoard(Table $table, Collection $seats): Board
  {
    $seats = $seats->reject(fn ($seat) => $seat->user->is_robot);

    // 1. a board none of these four has ever played
    $playedByAnyone = BoardTable::query()
      ->whereHas('seats', fn (Builder $q) => $q->whereIn('user_id', $seats->pluck('user_id')))
      ->select('board_id');

    $board = $this->unplayedAtTable($table)->whereNotIn('id', $playedByAnyone)->inRandomOrder()->first();

    if ($board !== null) {
      return $board;
    }

    // 2. failing that, one where nobody has held the seat they are taking now
    $playedFromSameSeat = BoardTable::query()
      ->whereHas('seats', function (Builder $q) use ($seats) {
        $q->where(function (Builder $pairs) use ($seats) {
          foreach ($seats as $seat) {
            $pairs->orWhere(
              fn (Builder $pair) => $pair->where('user_id', $seat->user_id)->where('seat', $seat->seat)
            );
          }
        });
      })
      ->select('board_id');

    $board = $this->unplayedAtTable($table)->whereNotIn('id', $playedFromSameSeat)->inRandomOrder()->first();

    if ($board !== null) {
      return $board;
    }

    // 3. every stored board is spoken for, so shuffle a new one rather than
    //    hand the table a deal somebody at it already knows
    return $this->dealBoard();
  }

  /**
   * Dealt boards this table has not played. A table never replays a board
   * (`unique(board_id, table_id)`), and a board with no hands is unplayable.
   *
   * @return Builder<Board>
   */
  private function unplayedAtTable(Table $table): Builder
  {
    return Board::query()
      ->has('cards', '=', 52)
      ->whereNotIn('id', BoardTable::query()->where('table_id', $table->id)->select('board_id'));
  }

  /**
   * The table's unfinished playing, if a board is in its auction or play.
   */
  public function openPlaying(Table $table): ?BoardTable
  {
    return BoardTable::query()
      ->where('table_id', $table->id)
      ->whereNull('finished_at')
      ->first();
  }
}

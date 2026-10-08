<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\Events\DeclarerHandShown;
use App\Events\PlayingUpdated;
use App\Events\TableUpdated;
use App\Events\UnseatedFromTable;
use App\Exceptions\SeatUnavailableException;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\TableSet;
use App\Models\TableSetSeat;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TableSeatService
{
  /** leave(): the seat was freed. */
  public const LEFT = 'left';

  /** leave(): the seat was freed and, nobody being left, the table deleted. */
  public const DELETED = 'deleted';

  /** leave(): mid-set, so the seat is held and its player marked away. */
  public const HELD = 'held';

  public function __construct(
    private BoardSelectionService $boardSelection,
    private PlayingStateService $playingState,
    private RobotPool $robots,
    private KibitzerService $kibitzers,
  ) {}

  /**
   * Seat a user at a table, holding every availability check.
   *
   * A user holds one seat at a time (`table_seats.unique(user_id)`). Somebody
   * taking a seat while they already hold one is **moved**: the old seat is
   * freed in the same transaction, so a client never has to leave and rejoin
   * and can never end up seated nowhere because the second call failed. A
   * move that cannot complete — the target seat went to somebody else — rolls
   * back and leaves them where they were.
   *
   * `$by` is who asked, when that isn't `$user` themselves (a manager seating
   * another player). Besides the wording of the error, it decides whether a
   * move is allowed at all: a manager may seat somebody who sits nowhere, but
   * pulling a player off a table they chose is not theirs to do, so that stays
   * a 409. A manager pointing at themselves counts as asking for themselves.
   *
   * A human sits down not ready (`ready_at` null): the board waits for their
   * Start (`BoardSelectionService::start()`). A robot is ready at once, so a
   * robot taking the last seat after every human has pressed Start deals the
   * table a board and opens its playing; this mutates `$table` (`board_id`).
   * Changing seat at the same table clears a human's Start too. A human
   * sitting down at an unattended table (only robots left there) becomes its
   * moderator. Moving off a table in the middle of a set hands the mover's
   * seat there to a robot at once (see remove(), `$walkOut`). A player a
   * robot took over from in the set going on here
   * (`table_set_seats.replaced_user_id`) may not sit down here again until
   * it is over. Sitting down ends any watching (`KibitzerService`): a
   * kibitzer has no seat anywhere.
   *
   * Broadcasts `TableUpdated` for the table sat at (and, through remove(), for
   * the table a move left), once the transaction commits.
   *
   * A banned user is never seated (`not-banned` refuses them their own
   * seat requests before this; here it stops a manager seating them).
   *
   * @throws SeatUnavailableException
   */
  public function seat(Table $table, User $user, string $seat, ?User $by = null): TableSeat
  {
    $self = $by === null || $by->id === $user->id;

    if (! $user->is_robot && ($ban = $user->activeBan()) !== null) {
      throw new SeatUnavailableException($self ? $ban->message() : 'That user is banned.');
    }

    try {
      return DB::transaction(function () use ($table, $user, $seat, $self) {
        if (! in_array($seat, Seats::SEATS, true)) {
          throw new SeatUnavailableException("Unknown seat '$seat'.");
        }

        $held = $user->seats()->first();

        if ($held !== null && ! $self) {
          throw new SeatUnavailableException('That user is already seated at a table.');
        }

        // both tables are locked up front, lowest id first, so two players
        // swapping tables at the same moment can't deadlock each other
        $this->lockTables($table, $held?->table_id);

        // reread under the lock: the last human may have left since $table
        // was loaded, leaving it unattended
        $table->refresh();

        if ($table->seats()->where('seat', $seat)->exists()) {
          throw new SeatUnavailableException("Seat $seat is already taken.");
        }

        if (! $user->is_robot && $this->boardSelection->currentSet($table)?->seats()->where('replaced_user_id', $user->id)->exists()) {
          throw new SeatUnavailableException($self
            ? 'You walked out on the set going on at this table: you may sit down here again once it is over.'
            : 'That user walked out on the set going on at this table.');
        }

        // changing seat at the table they already sit at: move the row rather
        // than leave and rejoin, which would delete the table under them if
        // they were its only player, and would lose their place in the
        // join order the moderator handover reads
        if ($held !== null && (int) $held->table_id === (int) $table->getKey()) {
          // Start belongs to the seat: sitting somewhere else, even at the
          // same table, means pressing it again
          $held->update([
            'seat' => $seat,
            'last_seen_at' => now(),
            'ready_at' => $user->is_robot ? $held->ready_at : null,
          ]);
          $this->boardSelection->syncStartDeadline($table);

          TableUpdated::dispatch($table);

          // a free target seat means the table wasn't full, so it cannot have
          // become full by shuffling one player around
          return $held;
        }

        if ($held !== null) {
          // through remove(), so leaving has all its usual consequences: the
          // old table goes if this was its last player, moderation is handed
          // on, and an unfinished playing is detached. Walking out on a set
          // there for another table hands the mover's seat to a robot
          $this->remove($held->table, $user, walkOut: TableSetSeat::REASON_MOVED);
        }

        // a human presses Start; a robot is ready from the start
        $seatRow = $table->seats()->create([
          'user_id' => $user->id,
          'seat' => $seat,
          'ready_at' => $user->is_robot ? now() : null,
        ]);

        // a player is no kibitzer, here or anywhere else
        $this->kibitzers->stopWatching($user, $table);

        // the first human back at a table only robots were keeping runs it
        if ($table->unattended_since !== null && ! $user->is_robot) {
          $table->update(['unattended_since' => null, 'moderated_by' => $user->id]);
        }

        // a robot filling the table after every human pressed Start deals;
        // a human sitting down never does, they have still to press it
        $this->boardSelection->startIfReady($table);
        // or, everyone else being ready, time the one who isn't
        $this->boardSelection->syncStartDeadline($table);

        TableUpdated::dispatch($table);

        return $seatRow;
      });
    } catch (QueryException $e) {
      // race fallback: unique(table_id, seat) or unique(user_id) hit by a concurrent request
      if ((string) $e->getCode() === '23000') {
        throw new SeatUnavailableException('The seat or user was taken by another request.', 0, $e);
      }

      throw $e;
    }
  }

  /**
   * Take the row locks for every table a seat change touches, in a fixed
   * order so concurrent moves in opposite directions queue instead of
   * deadlocking.
   */
  private function lockTables(Table $table, ?int $otherTableId): void
  {
    $ids = array_values(array_unique(array_filter([(int) $table->getKey(), (int) $otherTableId])));
    sort($ids);

    foreach ($ids as $id) {
      Table::whereKey($id)->lockForUpdate()->firstOrFail();
    }
  }

  /**
   * `$user` leaves their seat themselves (Leave, or a quit through
   * `DELETE /tables/{table}/seats/{user}`).
   *
   * In the middle of a set, when leaving would be walking out on it
   * (walksOut()), that is going away: the seat is **held** — they stay
   * seated, `away_since` set to now — until `replace_at`
   * (`bridge.away_replace_seconds` on), whoever's turn it is: then
   * (checkAway()) a robot takes their seat for the rest of the set, unless
   * they come back (touch()) first. Meanwhile their time for the set runs
   * whenever the board waits for them. Leaving again changes nothing.
   * Otherwise the seat is freed at once through remove().
   *
   * Returns LEFT, DELETED (the table went with the seat) or HELD.
   * Broadcasts `TableUpdated` when the seat is newly held, besides what
   * remove() sends.
   *
   * @throws SeatUnavailableException
   */
  public function leave(Table $table, User $user): string
  {
    return DB::transaction(function () use ($table, $user) {
      Table::whereKey($table->getKey())->lockForUpdate()->firstOrFail();
      $table->refresh();

      $seat = $table->seats()->with('user')->where('user_id', $user->id)->first();

      if ($seat !== null && $this->walksOut($table, $seat)) {
        // somebody already away keeps their earlier start: they have been
        // gone that long
        if ($seat->away_since === null) {
          $seat->update(['away_since' => now(), 'replace_at' => $this->replaceAt($seat, now())]);

          TableUpdated::dispatch($table);
        }

        return self::HELD;
      }

      return $this->remove($table, $user) ? self::DELETED : self::LEFT;
    });
  }

  /**
   * When a robot takes the seat of a player away since `$since`: never for
   * an admin, whom the table waits for.
   */
  private function replaceAt(TableSeat $seat, Carbon $since): ?Carbon
  {
    return $seat->user->is_admin ? null : $since->copy()->addSeconds((int) config('bridge.away_replace_seconds'));
  }

  /**
   * Whether seating `$user` at `$table` would hand their seat at the table
   * they sit at now to a robot, walking out on a set there (see seat()).
   * Read without a lock, for the response's wording: seat() decides again
   * under it.
   */
  public function moveReplaces(Table $table, User $user): bool
  {
    $held = $user->seats()->with('table')->first();

    return $held !== null
      && (int) $held->table_id !== (int) $table->getKey()
      && $this->removalReplaces($held->table, $user, TableSetSeat::REASON_MOVED);
  }

  /**
   * Whether remove() taking `$user` out of `$table` (with `$walkOut`, as
   * remove() takes it) would hand their seat to a robot for the rest of the
   * set. Read without a lock, for the response's wording: remove() decides
   * again under it.
   */
  public function removalReplaces(Table $table, User $user, ?string $walkOut = null): bool
  {
    $seat = $table->seats()->with('user')->where('user_id', $user->id)->first();

    return $seat !== null && $this->replacementReason($table, $seat, $walkOut) !== null;
  }

  /**
   * Why a robot takes `$seat` over when its player goes, or null when it
   * doesn't. Mid-set it does when they ran out of time on their turn
   * (`$walkOut` `turn_timeout`, `set_time` or `away`), walk out on the set (`moved`, or
   * `kicked` for a ban), or are taken out while away (`kicked`), as far as
   * walksOut() says so — and only while a human stays at the table for the
   * robot to play with.
   */
  private function replacementReason(Table $table, TableSeat $seat, ?string $walkOut): ?string
  {
    $outOfTime = in_array($walkOut, [TableSetSeat::REASON_TURN_TIMEOUT, TableSetSeat::REASON_SET_TIME, TableSetSeat::REASON_AWAY], true);
    $reason = $walkOut ?? ($seat->away_since !== null ? TableSetSeat::REASON_KICKED : null);

    $othersStay = $table->seats()
      ->whereKeyNot($seat->getKey())
      ->whereHas('user', fn ($user) => $user->humans())
      ->exists();

    return $reason !== null && $othersStay && $this->walksOut($table, $seat, $outOfTime) ? $reason : null;
  }

  /**
   * Whether `$seat`'s player going away is walking out on the set, which
   * hands their seat to a robot for the rest of it: a human in the middle of
   * a set is, except an admin, and except while an admin at the table is
   * away themselves: the set can't go on then through nobody else's fault,
   * so the others may leave it as they like (it ends `abandoned`). Running
   * out of time on one's own turn (`$outOfTime`) is one's own doing, admin
   * away or not. A robot is never away.
   */
  private function walksOut(Table $table, TableSeat $seat, bool $outOfTime = false): bool
  {
    if ($seat->user->is_robot || $seat->user->is_admin) {
      return false;
    }

    if ($this->boardSelection->currentSet($table) === null) {
      return false;
    }

    return $outOfTime || ! $table->seats()
      ->whereNotNull('away_since')
      ->whereHas('user', fn ($user) => $user->where('is_admin', true))
      ->exists();
  }

  /**
   * Free the seat a user holds at a table, whether they quit or a manager
   * kicked them out.
   *
   * `$by` is who asked, when that isn't `$user` themselves (a manager
   * removing another player); it only changes the wording of the error.
   *
   * This frees the seat at once, whoever asked: a player's own Leave goes
   * through leave(), which holds the seat instead in the middle of a set.
   * Mid-set, a player taken out for running out of time on their turn
   * (`$walkOut` `turn_timeout`, `set_time` or `away`, from checkAway()), walking out on
   * the set (`moved`: a move to another table from seat(); `kicked`: a ban
   * from `UserBanService::ban()`) or kicked while away has a **robot take
   * their seat** for the rest of the set (replaceWithRobot(), with that
   * reason), unless walksOut() lets them off or no human would be left:
   * the set and the board in progress go on, and they may not sit down
   * here again until the set is over. Anyone else leaving mid-set abandons
   * it.
   *
   * A table lives only while somebody sits at it, so removing the last player
   * deletes it. If a human is left and the leaver was the moderator, the role
   * passes to the human seated here longest (earliest seat row), the creator
   * included but not preferred; robots never get it. If only robots are
   * left, the table is kept as unattended (`unattended_since`, no
   * moderator): its robots wait, anyone may kick them, and the first human
   * to sit down runs it (see seat()). `created_by` never moves and grants
   * nothing: it is what the per-creator active-table limit counts.
   *
   * Short of a robot taking over, a playing that was under way is dropped:
   * the four who started it are no longer the four sitting there. See
   * `BoardSelectionService::abandonPlaying`. So is an unfinished set, even
   * between boards: it ends `abandoned` (`BoardSelectionService::abandonSet`),
   * and the next board opens a new one. The set records `$user` as having
   * ended it (`ended_by`) unless somebody else kicked them while they were
   * there.
   * The leaver's Start goes with their seat row; whoever takes the seat next
   * has to press it. Any Start timer stops (the table is short of a player,
   * or mid-set, where there is none).
   *
   * Mutates `$table` (moderator handover, `board_id`) and returns true if the
   * table was deleted. Broadcasts `TableUpdated` unless it was, and
   * `PlayingUpdated` when a robot took over a board: `$quietly` leaves both
   * to the caller, which removes several players at once and tells the
   * table once (checkAway()).
   *
   * @throws SeatUnavailableException
   */
  public function remove(Table $table, User $user, ?User $by = null, ?string $walkOut = null, bool $quietly = false): bool
  {
    return DB::transaction(function () use ($table, $user, $by, $walkOut, $quietly) {
      // serialize seat changes on this table, then reread it: who runs it
      // may have changed since it was loaded
      Table::whereKey($table->getKey())->lockForUpdate()->firstOrFail();
      $table->refresh();

      $seat = $table->seats()->with('user')->where('user_id', $user->id)->first();

      if ($seat === null) {
        throw new SeatUnavailableException(
          $by === null || $by->id === $user->id
            ? 'You are not seated at this table.'
            : 'That user is not seated at this table.'
        );
      }

      // read before the seat goes: whether an admin there is away
      $reason = $this->replacementReason($table, $seat, $walkOut);
      // whose abandon it is, should the set end here: anyone's own going,
      // not a player who was there being kicked
      $leaver = $walkOut !== null || $seat->away_since !== null || $by === null || $by->id === $user->id ? $user : null;

      $seat->delete();

      if ($reason !== null) {
        $this->replaceWithRobot($table, $seat, $reason, $quietly);
      } else {
        // whoever is left is not the four who started the board, nor the set
        $this->boardSelection->abandonPlaying($table);
        $this->boardSelection->abandonSet($table, $leaver);
      }

      // the human seated here longest, if any: a robot never runs a table
      $next = $table->seats()
        ->whereHas('user', fn ($user) => $user->humans())
        ->orderBy('created_at')
        ->orderBy('id')
        ->first();

      if ($next === null && ! $table->seats()->exists()) {
        $table->delete();

        return true;
      }

      if ($next === null) {
        // only robots are left: they keep the table, idle, until a human
        // sits down or tables:delete-unattended deletes it
        $table->update(['moderated_by' => null, 'unattended_since' => $table->unattended_since ?? now()]);
      } elseif ((int) $table->moderated_by === $user->id) {
        $table->update(['moderated_by' => $next->user_id]);
      }

      // the table is short of a player now: any Start timer stops
      $this->boardSelection->syncStartDeadline($table);

      // a deleted table has nobody left to tell
      if (! $quietly) {
        TableUpdated::dispatch($table);
      }

      return false;
    });
  }

  /**
   * Sit a robot in the seat `$gone` just freed, for the rest of the set, and
   * write down whom it took over from and why (`$reason`) on the set's seat
   * (`table_set_seats.replaced_user_id`). It is ready at once.
   *
   * On a board in progress it takes the hand over where it is: the
   * snapshot's seat (`board_table_seats`) becomes the robot's, keeping the
   * human as `replaced_user_id` since they have seen the deal, and the
   * board waits afresh (`turn_started_at`): who acts may have changed, so
   * whoever's time for the set was running is charged first
   * (`BoardTable::chargeTurn()`). The robot has no time bank; the seat
   * keeps its human's (`time_left_ms`), for the set's results. A
   * finished board keeps its four: it is theirs, and the robot plays from
   * the next one. A human dummy whose declarer the robot now is plays both
   * hands from here (`DeclarerHandShown`). `PlayingUpdated` tells the table
   * and gets the robots moving, unless `$quietly` leaves it to the caller.
   *
   * Run by remove(), under the table lock.
   */
  private function replaceWithRobot(Table $table, TableSeat $gone, string $reason, bool $quietly): void
  {
    // whoever's time for the set was running, up to now: the board waits
    // afresh below
    $open = $this->boardSelection->openPlaying($table);
    $open?->chargeTurn();

    $robot = $this->sitRobot($table, $gone->seat);

    $this->boardSelection->currentSet($table)->seats()->where('seat', $gone->seat)->update([
      'user_id' => $robot->id,
      'replaced_user_id' => $gone->user_id,
      'replaced_reason' => $reason,
      'replaced_at' => now(),
    ]);

    $open?->seats()->where('seat', $gone->seat)->update(['user_id' => $robot->id, 'replaced_user_id' => $gone->user_id]);
    $open?->update(['turn_started_at' => now()]);

    $playing = $this->playingState->currentPlaying($table);

    if ($playing === null) {
      return;
    }

    if ($open !== null && $this->playingState->declarerHandFor($playing, Seats::partner($gone->seat)) !== null) {
      DeclarerHandShown::dispatch($playing, (int) $playing->seats->firstWhere('seat', Seats::partner($gone->seat))->user_id, Seats::partner($gone->seat));
    }

    if (! $quietly) {
      PlayingUpdated::dispatch($table);
    }
  }

  /**
   * Sit an idle robot from the pool in `$seat`, ready. Another table may
   * seat the same robot at the same moment (`unique(user_id)`): then the
   * next one is tried.
   */
  private function sitRobot(Table $table, string $seat): User
  {
    $tried = [];

    for ($attempt = 1; ; $attempt++) {
      $robot = $this->robots->idle($tried);
      $tried[] = $robot->id;

      try {
        // a savepoint, so a refused insert leaves the outer transaction usable
        DB::transaction(fn () => $table->seats()->create(['user_id' => $robot->id, 'seat' => $seat, 'ready_at' => now()]));

        return $robot;
      } catch (QueryException $e) {
        if ((string) $e->getCode() !== '23000' || $attempt >= RobotPool::ATTEMPTS) {
          throw $e;
        }
      }
    }
  }

  /**
   * Record a sign of life from a player at a table (`last_seen_at`), so the
   * idle-seat sweeper leaves them alone. A player marked away (checkAway(),
   * or a Leave mid-set) is back: `away_since` and `replace_at` are cleared,
   * and the table told (`TableUpdated`). Being there isn't playing: it
   * never touches the turn clock (`PlayingStateService::turnDeadline()`),
   * except that one coming back on their turn gets the turn clock back,
   * from now (restartTurn()). A no-op for somebody who doesn't sit there.
   * Leaves `updated_at` alone: nothing about the seat changed.
   */
  public function touch(Table $table, User $user): void
  {
    $seat = fn () => TableSeat::query()
      ->where('table_id', $table->getKey())
      ->where('user_id', $user->id)
      ->toBase();

    $seat()->update(['last_seen_at' => now()]);

    if ($seat()->whereNotNull('away_since')->update(['away_since' => null, 'replace_at' => null]) > 0) {
      $this->restartTurn($table, $user);

      TableUpdated::dispatch($table);
    }
  }

  /**
   * `$user`, back from being away, is the one the board waits for: while
   * away only their seat's `replace_at` and their time for the set could
   * end their turn, so their time is charged up to now
   * (`BoardTable::chargeTurn()`) and the turn clock starts afresh
   * (`turn_started_at`). `PlayingUpdated` tells the table the new
   * `turn_deadline`.
   */
  private function restartTurn(Table $table, User $user): void
  {
    DB::transaction(function () use ($table, $user) {
      $playing = $this->playingState->currentPlaying($table, lock: true);

      if ($this->playingState->clockedUser($playing)?->id !== $user->id) {
        return;
      }

      $playing->chargeTurn();
      $playing->update(['turn_started_at' => now()]);

      PlayingUpdated::dispatch($table);
    });
  }

  /**
   * Free the seat of every player who has gone quiet for longer than
   * `bridge.idle_seat_minutes`, at a table that isn't in the middle of a set:
   * there checkAway() decides instead. An admin's seat is never freed: only
   * the admin or another admin may take it (`TablePolicy::kick`). Each goes
   * out through remove(),
   * exactly like a leave: moderation is handed on, an emptied table is
   * deleted and the others are told.
   *
   * Returns how many seats were freed.
   */
  public function releaseIdleSeats(): int
  {
    $cutoff = now()->subMinutes(config('bridge.idle_seat_minutes'));

    // robots send no heartbeat and are never idle; nor is an admin
    $candidates = TableSeat::query()
      ->whereHas('user', fn ($user) => $user->humans()->where('is_admin', false))
      ->where('last_seen_at', '<', $cutoff)
      ->whereNotIn('table_id', $this->tablesMidSet())
      ->orderBy('id')
      ->pluck('id');

    $freed = 0;

    foreach ($candidates as $seatId) {
      if ($this->releaseIfIdle($seatId, $cutoff)) {
        $freed++;
      }
    }

    return $freed;
  }

  /**
   * The away rule and the turn clock, run every ten seconds by
   * `tables:check-away`. At a table in the middle of a set:
   * - a human with no sign of life for `bridge.away_seconds` is marked away,
   *   `away_since` being that last sign of life; their seat stays theirs
   *   until `replace_at` (`bridge.away_replace_seconds` after `away_since`;
   *   none for an admin);
   * - every player whose time is up is taken out through remove(), where a
   *   robot takes their seat for the rest of the set: anyone away whose
   *   `replace_at` has come, whoever's turn it is (`away`), and the player
   *   the board waits for once their turn has run out
   *   (`PlayingStateService::turnClock()`: `set_time` if it was their time
   *   for the set, `away` if they were away, otherwise `turn_timeout`). All
   *   of them at once, and the table told once (`TableUpdated`, and
   *   `PlayingUpdated` to get the robots moving). No clock runs for a robot
   *   or an admin: the table just waits for an admin. Should nobody but
   *   them be left to play with, the set ends `abandoned` instead, ended by
   *   whoever's time ran out first, and they are freed as by a leave.
   * At a table whose set is over (completed or abandoned),
   * nobody is held for it any more: the seats of players still away are
   * freed, as a leave (an admin's is kept, and only stops being away).
   *
   * Each table is checked under its lock. Returns how many players were
   * marked away, how many players ran out of time (replaced, or freed as
   * the set ended) and how many seats were freed.
   *
   * @return array{away: int, timed_out: int, freed: int}
   */
  public function checkAway(): array
  {
    $awayCutoff = now()->subSeconds(config('bridge.away_seconds'));

    $awayOrQuiet = TableSeat::query()
      ->whereHas('user', fn ($user) => $user->humans())
      ->where(fn ($seat) => $seat
        ->whereNotNull('away_since')
        ->orWhere(fn ($quiet) => $quiet
          ->where('last_seen_at', '<', $awayCutoff)
          ->whereIn('table_id', $this->tablesMidSet())))
      ->distinct()
      ->pluck('table_id');

    // a board waiting longer than any turn clock allows, or in a set where
    // somebody has less time left than a turn: whether the one it waits for
    // has a clock at all, and whether it has run out, is for checkAwayAt()
    // to say
    $turn = (int) config('bridge.turn_seconds');
    $overdue = BoardTable::query()
      ->whereNotNull('table_id')
      ->whereNull('finished_at')
      ->whereNull('claim_seat')
      ->where(fn ($late) => $late
        ->where('turn_started_at', '<=', now()->subSeconds($turn))
        ->orWhereIn('table_set_id', TableSetSeat::query()->where('time_left_ms', '<', $turn * 1000)->select('table_set_id')))
      ->pluck('table_id');

    $tables = $awayOrQuiet->merge($overdue)->map(fn ($id) => (int) $id)->unique()->sort()->values();

    $counts = ['away' => 0, 'timed_out' => 0, 'freed' => 0];

    foreach ($tables as $tableId) {
      foreach ($this->checkAwayAt($tableId, $awayCutoff) as $key => $count) {
        $counts[$key] += $count;
      }
    }

    return $counts;
  }

  /**
   * checkAway() for one table, under its lock.
   *
   * @return array{away: int, timed_out: int, freed: int}
   */
  private function checkAwayAt(int $tableId, Carbon $awayCutoff): array
  {
    return DB::transaction(function () use ($tableId, $awayCutoff) {
      $counts = ['away' => 0, 'timed_out' => 0, 'freed' => 0];
      $table = Table::whereKey($tableId)->lockForUpdate()->first();

      if ($table === null) {
        return $counts;
      }

      $humans = fn () => $table->seats()->with('user')->whereHas('user', fn ($user) => $user->humans())->get();

      if ($this->boardSelection->currentSet($table) !== null) {
        foreach ($humans() as $seat) {
          if ($seat->away_since === null && $seat->last_seen_at->lt($awayCutoff)) {
            $seat->update(['away_since' => $seat->last_seen_at, 'replace_at' => $this->replaceAt($seat, $seat->last_seen_at)]);
            $counts['away']++;
          }
        }

        $due = $this->timeUp($table, $humans());

        if ($due === []) {
          if ($counts['away'] > 0) {
            TableUpdated::dispatch($table);
          }

          return $counts;
        }

        $counts['timed_out'] = count($due);

        if ($humans()->whereNotIn('user_id', array_keys($due))->isEmpty()) {
          // a robot would have nobody to play with: the set ends, as if the
          // first to run out had left, and they all go
          $this->boardSelection->abandonPlaying($table);
          $this->boardSelection->abandonSet($table, reset($due)['seat']->user);
        }

        foreach ($due as ['seat' => $seat, 'reason' => $reason]) {
          if ($this->remove($table, $seat->user, walkOut: $reason, quietly: true)) {
            return $counts;
          }
        }

        TableUpdated::dispatch($table);

        if ($this->playingState->currentPlaying($table) !== null) {
          PlayingUpdated::dispatch($table);
        }

        if ($this->boardSelection->currentSet($table) !== null) {
          return $counts;
        }
      }

      $cleared = false;

      foreach ($humans()->whereNotNull('away_since') as $seat) {
        // no timer frees an admin's seat
        if ($seat->user->is_admin) {
          $seat->update(['away_since' => null]);
          $cleared = true;

          continue;
        }

        $counts['freed']++;

        if ($this->remove($table, $seat->user)) {
          return $counts;
        }
      }

      if ($cleared) {
        TableUpdated::dispatch($table);
      }

      return $counts;
    });
  }

  /**
   * The players at a table in the middle of a set whose time is up, by
   * user id, with why (`TableSetSeat::REASONS`) and when it ran out,
   * earliest first: anyone away past their seat's `replace_at`, and the
   * player the board waits for past `PlayingStateService::turnClock()`.
   *
   * @param  Collection<int, TableSeat>  $humans
   * @return array<int, array{seat: TableSeat, reason: string, at: Carbon}>
   */
  private function timeUp(Table $table, Collection $humans): array
  {
    $due = [];

    foreach ($humans as $seat) {
      if ($seat->replace_at?->lte(now()) && ! $seat->user->is_admin) {
        $due[$seat->user_id] = ['seat' => $seat, 'reason' => TableSetSeat::REASON_AWAY, 'at' => $seat->replace_at];
      }
    }

    $playing = $this->playingState->currentPlaying($table);
    $clock = $this->playingState->turnClock($playing);
    $late = $clock !== null && $clock['deadline']->lte(now())
      ? $humans->firstWhere('user_id', $this->playingState->actingUserId($playing))
      : null;

    if ($late !== null) {
      $due[$late->user_id] = ['seat' => $late, 'reason' => match ($clock['by']) {
        PlayingStateService::DEADLINE_BY_SET => TableSetSeat::REASON_SET_TIME,
        PlayingStateService::DEADLINE_BY_AWAY => TableSetSeat::REASON_AWAY,
        default => TableSetSeat::REASON_TURN_TIMEOUT,
      }, 'at' => $clock['deadline']];
    }

    uasort($due, fn ($a, $b) => [$a['at']->getTimestamp(), $a['seat']->id] <=> [$b['at']->getTimestamp(), $b['seat']->id]);

    return $due;
  }

  /**
   * The ids of the tables in the middle of a set, as a subquery.
   *
   * @return Builder<TableSet>
   */
  private function tablesMidSet(): Builder
  {
    return TableSet::query()->whereNull('finished_at')->whereNotNull('table_id')->select('table_id');
  }

  /**
   * Delete every table only robots have kept for longer than
   * `bridge.unattended_table_minutes`, each rechecked under its lock in case
   * a human sat down meanwhile. An unfinished playing is detached first, as
   * when a player leaves. Nobody is told: no human sits there.
   *
   * Returns how many tables were deleted.
   */
  public function deleteUnattendedTables(): int
  {
    $cutoff = now()->subMinutes(config('bridge.unattended_table_minutes'));

    $candidates = Table::query()
      ->where('unattended_since', '<', $cutoff)
      ->orderBy('id')
      ->pluck('id');

    $deleted = 0;

    foreach ($candidates as $tableId) {
      $deleted += (int) DB::transaction(function () use ($tableId, $cutoff) {
        $table = Table::whereKey($tableId)->lockForUpdate()->first();

        if ($table?->unattended_since === null || $table->unattended_since->gte($cutoff)
          || $table->seats()->whereHas('user', fn ($user) => $user->humans())->exists()) {
          return false;
        }

        $this->boardSelection->abandonPlaying($table);
        $table->seats()->delete();
        $table->delete();

        return true;
      });
    }

    return $deleted;
  }

  /**
   * Free a seat whose Start timer has run out (`start_deadline`, see
   * `BoardSelectionService::syncStartDeadline()`), run by the queued
   * `ExpireStart`. Rechecked under the table lock: by now they may have
   * pressed Start, the timer may have stopped or started afresh, or the
   * seat may be gone. The seat goes through remove(), exactly like a
   * leave: moderation is handed on (a moderator or an admin is no
   * exception), and the others are told. Nothing is held against the
   * player, who may sit down again at once. At a table that allows
   * kibitzers they stay as one (`KibitzerService::admit()`), so they can
   * still watch it; they are told on their own channel
   * (`UnseatedFromTable`, `start_timeout`, `kibitzing`).
   *
   * Returns whether the seat was freed.
   */
  public function expireStart(int $seatId): bool
  {
    return DB::transaction(function () use ($seatId) {
      $tableId = TableSeat::whereKey($seatId)->value('table_id');

      if ($tableId === null) {
        return false;
      }

      $table = Table::whereKey($tableId)->lockForUpdate()->first();
      $seat = TableSeat::whereKey($seatId)->where('table_id', $tableId)->with('user')->first();

      if ($table === null || $seat?->start_deadline === null || $seat->start_deadline->isFuture()) {
        return false;
      }

      // at a table that allows it they stay, as a kibitzer: in before the
      // seat goes, so the TableUpdated remove() sends counts them
      if ($table->allow_kibitzers) {
        $this->kibitzers->admit($table, $seat->user);
      }

      $deleted = $this->remove($table, $seat->user);

      UnseatedFromTable::dispatch(
        $seat->user_id,
        $table->id,
        UnseatedFromTable::REASON_START_TIMEOUT,
        $table->allow_kibitzers && ! $deleted
      );

      return true;
    });
  }

  /**
   * Free one seat if it is still idle once its table is locked: between the
   * sweep's query and here the player may have sent a heartbeat, moved or
   * left, or the table may have started a set.
   */
  private function releaseIfIdle(int $seatId, Carbon $cutoff): bool
  {
    return DB::transaction(function () use ($seatId, $cutoff) {
      $tableId = TableSeat::whereKey($seatId)->value('table_id');

      if ($tableId === null) {
        return false;
      }

      $table = Table::whereKey($tableId)->lockForUpdate()->first();
      $seat = TableSeat::whereKey($seatId)->where('table_id', $tableId)->with('user')->first();

      if ($table === null || $seat === null) {
        return false;
      }

      if ($seat->last_seen_at->gte($cutoff) || $this->boardSelection->currentSet($table) !== null) {
        return false;
      }

      $this->remove($table, $seat->user);

      return true;
    });
  }
}

<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\Events\TableUpdated;
use App\Exceptions\SeatUnavailableException;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\TableSet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

  public function __construct(private BoardSelectionService $boardSelection) {}

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
   * moderator. Moving off a table in the middle of a set forfeits that set
   * for the mover's side at once (see remove(), `$walkOut`).
   *
   * Broadcasts `TableUpdated` for the table sat at (and, through remove(), for
   * the table a move left), once the transaction commits.
   *
   * @throws SeatUnavailableException
   */
  public function seat(Table $table, User $user, string $seat, ?User $by = null): TableSeat
  {
    $self = $by === null || $by->id === $user->id;

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

          TableUpdated::dispatch($table);

          // a free target seat means the table wasn't full, so it cannot have
          // become full by shuffling one player around
          return $held;
        }

        if ($held !== null) {
          // through remove(), so leaving has all its usual consequences: the
          // old table goes if this was its last player, moderation is handed
          // on, and an unfinished playing is detached. Walking out on a set
          // there for another table costs the mover's side that set
          $this->remove($held->table, $user, walkOut: true);
        }

        // a human presses Start; a robot is ready from the start
        $seatRow = $table->seats()->create([
          'user_id' => $user->id,
          'seat' => $seat,
          'ready_at' => $user->is_robot ? now() : null,
        ]);

        // the first human back at a table only robots were keeping runs it
        if ($table->unattended_since !== null && ! $user->is_robot) {
          $table->update(['unattended_since' => null, 'moderated_by' => $user->id]);
        }

        // a robot filling the table after every human pressed Start deals;
        // a human sitting down never does, they have still to press it
        $this->boardSelection->startIfReady($table);

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
   * In the middle of a set, when leaving would cost their side the set
   * (costsTheSet()), that is going away: the seat is **held** — they stay
   * seated, `away_since` set to now — and their side forfeits the set once
   * they have been away `bridge.set_forfeit_minutes` (checkAway()), unless
   * a sign of life (touch()) brings them back first. Leaving again changes
   * nothing. Otherwise the seat is freed at once through remove().
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

      if ($seat !== null && $this->costsTheSet($table, $seat)) {
        // somebody already away keeps their earlier start: they have been
        // gone that long
        if ($seat->away_since === null) {
          $seat->update(['away_since' => now()]);

          TableUpdated::dispatch($table);
        }

        return self::HELD;
      }

      return $this->remove($table, $user) ? self::DELETED : self::LEFT;
    });
  }

  /**
   * Whether seating `$user` at `$table` would forfeit a set at the table
   * they sit at now (see seat()). Read without a lock, for the response's
   * wording: seat() decides again under it.
   */
  public function moveForfeits(Table $table, User $user): bool
  {
    $held = $user->seats()->with('table')->first();

    return $held !== null
      && (int) $held->table_id !== (int) $table->getKey()
      && $this->removalForfeits($held->table, $user, walkOut: true);
  }

  /**
   * Whether remove() taking `$user` out of `$table` would forfeit the set
   * there for their side. Read without a lock, for the response's wording:
   * remove() decides again under it.
   */
  public function removalForfeits(Table $table, User $user, bool $walkOut = false): bool
  {
    $seat = $table->seats()->with('user')->where('user_id', $user->id)->first();

    return $seat !== null && $this->forfeits($table, $seat, $walkOut);
  }

  /**
   * Whether `$seat` going costs its side the set: it does mid-set when its
   * player is away, or is walking out on the set for another table.
   */
  private function forfeits(Table $table, TableSeat $seat, bool $walkOut): bool
  {
    return ($walkOut || $seat->away_since !== null) && $this->costsTheSet($table, $seat);
  }

  /**
   * Whether `$seat`'s player going away costs their side the set: a human
   * in the middle of a set does, except an admin, whose absence never costs
   * their side anything, and except while an admin at the table is away
   * themselves: the set can't go on then through nobody else's fault, so the
   * others may leave it without penalty (it ends `abandoned`). A robot is
   * never away.
   */
  private function costsTheSet(Table $table, TableSeat $seat): bool
  {
    if ($seat->user->is_robot || $seat->user->is_admin) {
      return false;
    }

    if ($this->boardSelection->currentSet($table) === null) {
      return false;
    }

    return ! $table->seats()
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
   * Mid-set, a player taken out while away (by checkAway(), or kicked) or
   * walking out on the set for another table (`$walkOut`, from seat())
   * costs their side the set (`BoardSelectionService::forfeitSet()`), unless
   * costsTheSet() lets them off; anyone else leaving mid-set abandons it.
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
   * A playing that was under way is dropped: the four who started it are no
   * longer the four sitting there. See `BoardSelectionService::abandonPlaying`.
   * So is an unfinished set, even between boards: it ends `abandoned`
   * (`BoardSelectionService::abandonSet`) or `forfeit`, and the next board
   * opens a new one.
   * The leaver's Start goes with their seat row; whoever takes the seat next
   * has to press it.
   *
   * Mutates `$table` (moderator handover, `board_id`) and returns true if the
   * table was deleted. Broadcasts `TableUpdated` unless it was.
   *
   * @throws SeatUnavailableException
   */
  public function remove(Table $table, User $user, ?User $by = null, bool $walkOut = false): bool
  {
    return DB::transaction(function () use ($table, $user, $by, $walkOut) {
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
      $forfeit = $this->forfeits($table, $seat, $walkOut);

      $seat->delete();

      if ($forfeit) {
        $this->boardSelection->forfeitSet($table, Seats::side($seat->seat));
      }

      // whoever is left is not the four who started the board, nor the set
      // (which abandonSet() ends unless the forfeit just has)
      $this->boardSelection->abandonPlaying($table);
      $this->boardSelection->abandonSet($table);

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

        TableUpdated::dispatch($table);

        return false;
      }

      if ((int) $table->moderated_by === $user->id) {
        $table->update(['moderated_by' => $next->user_id]);
      }

      // a deleted table has nobody left to tell
      TableUpdated::dispatch($table);

      return false;
    });
  }

  /**
   * Record a sign of life from a player at a table (`last_seen_at`), so the
   * idle-seat sweeper leaves them alone. A player marked away (checkAway(),
   * or a Leave mid-set) is back: `away_since` is cleared, and the table told
   * (`TableUpdated`). A no-op for somebody who doesn't sit there. Leaves
   * `updated_at` alone: nothing about the seat changed.
   */
  public function touch(Table $table, User $user): void
  {
    $seat = fn () => TableSeat::query()
      ->where('table_id', $table->getKey())
      ->where('user_id', $user->id)
      ->toBase();

    $seat()->update(['last_seen_at' => now()]);

    if ($seat()->whereNotNull('away_since')->update(['away_since' => null]) > 0) {
      TableUpdated::dispatch($table);
    }
  }

  /**
   * Free the seat of every player who has gone quiet for longer than
   * `bridge.idle_seat_minutes`, at a table that isn't in the middle of a set:
   * there checkAway() decides instead. Each goes out through remove(),
   * exactly like a leave: moderation is handed on, an emptied table is
   * deleted and the others are told.
   *
   * Returns how many seats were freed.
   */
  public function releaseIdleSeats(): int
  {
    $cutoff = now()->subMinutes(config('bridge.idle_seat_minutes'));

    // robots send no heartbeat and are never idle
    $candidates = TableSeat::query()
      ->whereHas('user', fn ($user) => $user->humans())
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
   * The away rule, run every ten seconds by `tables:check-away`. At a table
   * in the middle of a set:
   * - a human with no sign of life for `bridge.away_seconds` is marked away,
   *   `away_since` being that last sign of life; their seat stays theirs;
   * - a human away for `bridge.set_forfeit_minutes` is taken out through
   *   remove(), which forfeits the set for their side — unless
   *   costsTheSet() lets them off (an admin, or anyone while an admin there
   *   is away), in which case the table just waits.
   * At a table whose set is over (completed, abandoned or forfeited),
   * nobody is held for it any more: the seats of players still away are
   * freed, as a leave (an admin's is kept, and only stops being away).
   *
   * Each table is checked under its lock. Returns how many players were
   * marked away, how many sets were forfeited and how many seats freed.
   *
   * @return array{away: int, forfeited: int, freed: int}
   */
  public function checkAway(): array
  {
    $awayCutoff = now()->subSeconds(config('bridge.away_seconds'));
    $forfeitCutoff = now()->subMinutes(config('bridge.set_forfeit_minutes'));

    $tables = TableSeat::query()
      ->whereHas('user', fn ($user) => $user->humans())
      ->where(fn ($seat) => $seat
        ->whereNotNull('away_since')
        ->orWhere(fn ($quiet) => $quiet
          ->where('last_seen_at', '<', $awayCutoff)
          ->whereIn('table_id', $this->tablesMidSet())))
      ->distinct()
      ->orderBy('table_id')
      ->pluck('table_id');

    $counts = ['away' => 0, 'forfeited' => 0, 'freed' => 0];

    foreach ($tables as $tableId) {
      foreach ($this->checkAwayAt($tableId, $awayCutoff, $forfeitCutoff) as $key => $count) {
        $counts[$key] += $count;
      }
    }

    return $counts;
  }

  /**
   * checkAway() for one table, under its lock.
   *
   * @return array{away: int, forfeited: int, freed: int}
   */
  private function checkAwayAt(int $tableId, Carbon $awayCutoff, Carbon $forfeitCutoff): array
  {
    return DB::transaction(function () use ($tableId, $awayCutoff, $forfeitCutoff) {
      $counts = ['away' => 0, 'forfeited' => 0, 'freed' => 0];
      $table = Table::whereKey($tableId)->lockForUpdate()->first();

      if ($table === null) {
        return $counts;
      }

      $humans = fn () => $table->seats()->with('user')->whereHas('user', fn ($user) => $user->humans())->get();

      if ($this->boardSelection->currentSet($table) !== null) {
        foreach ($humans() as $seat) {
          if ($seat->away_since === null && $seat->last_seen_at->lt($awayCutoff)) {
            $seat->update(['away_since' => $seat->last_seen_at]);
            $counts['away']++;
          }
        }

        // the one away longest whose time is up, if it costs their side
        $gone = $humans()
          ->filter(fn ($seat) => $seat->away_since?->lte($forfeitCutoff))
          ->sortBy('away_since')
          ->first(fn ($seat) => $this->costsTheSet($table, $seat));

        if ($gone !== null) {
          // remove() tells the table
          $this->remove($table, $gone->user);
          $counts['forfeited']++;
        } elseif ($counts['away'] > 0) {
          TableUpdated::dispatch($table);
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

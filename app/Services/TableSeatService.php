<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\Events\TableUpdated;
use App\Exceptions\SeatUnavailableException;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TableSeatService
{
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
   * moderator.
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
          // on, and an unfinished playing is detached
          $this->remove($held->table, $user);
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
   * Free the seat a user holds at a table, whether they quit or a manager
   * kicked them out.
   *
   * `$by` is who asked, when that isn't `$user` themselves (a manager
   * removing another player); it only changes the wording of the error.
   *
   * A table lives only while somebody sits at it, so removing the last player
   * deletes it. If a human is left and the leaver was the moderator, the role
   * passes to the creator when they are still seated, otherwise to the human
   * who joined earliest; robots never get it. If only robots are left, the
   * table is kept as unattended (`unattended_since`, no moderator): its
   * robots wait, anyone may kick them, and the first human to sit down runs
   * it (see seat()). A table is only ever managed by one person, and
   * `TablePolicy::manage` already treats a seated creator as that person.
   * `created_by` never moves: it is what the per-creator active-table limit
   * counts.
   *
   * A playing that was under way is dropped: the four who started it are no
   * longer the four sitting there. See `BoardSelectionService::abandonPlaying`.
   * The leaver's Start goes with their seat row; whoever takes the seat next
   * has to press it.
   *
   * Mutates `$table` (moderator handover, `board_id`) and returns true if the
   * table was deleted. Broadcasts `TableUpdated` unless it was.
   *
   * @throws SeatUnavailableException
   */
  public function remove(Table $table, User $user, ?User $by = null): bool
  {
    return DB::transaction(function () use ($table, $user, $by) {
      // serialize seat changes on this table, then reread it: who runs it
      // may have changed since it was loaded
      Table::whereKey($table->getKey())->lockForUpdate()->firstOrFail();
      $table->refresh();

      $seat = $table->seats()->where('user_id', $user->id)->first();

      if ($seat === null) {
        throw new SeatUnavailableException(
          $by === null || $by->id === $user->id
            ? 'You are not seated at this table.'
            : 'That user is not seated at this table.'
        );
      }

      $seat->delete();

      // whoever is left is not the four who started the board
      $this->boardSelection->abandonPlaying($table);

      // the creator if they are still here, else the earliest human joiner
      // still at the table, if any: a robot never runs a table
      $humans = $table->seats()->whereHas('user', fn ($user) => $user->humans());
      $next = (clone $humans)->where('user_id', $table->created_by)->first()
        ?? $humans->orderBy('created_at')->orderBy('id')->first();

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
   * idle-seat sweeper leaves them alone. A no-op for somebody who doesn't sit
   * there. Leaves `updated_at` alone: nothing about the seat changed.
   */
  public function touch(Table $table, User $user): void
  {
    TableSeat::query()
      ->where('table_id', $table->getKey())
      ->where('user_id', $user->id)
      ->toBase()
      ->update(['last_seen_at' => now()]);
  }

  /**
   * Free the seat of every player who has gone quiet for longer than
   * `bridge.idle_seat_minutes`, or `bridge.idle_playing_seat_minutes` while
   * their table is in the middle of a board. Each goes out through remove(),
   * exactly like a leave: moderation is handed on, an unfinished playing is
   * detached, an emptied table is deleted and the others are told.
   *
   * Returns how many seats were freed.
   */
  public function releaseIdleSeats(): int
  {
    $lobbyCutoff = now()->subMinutes(config('bridge.idle_seat_minutes'));
    $playingCutoff = now()->subMinutes(config('bridge.idle_playing_seat_minutes'));

    // every human's seat idle past the shorter timeout; each is then held to
    // the timeout its own table calls for. Robots send no heartbeat and are
    // never idle
    $candidates = TableSeat::query()
      ->whereHas('user', fn ($user) => $user->humans())
      ->where('last_seen_at', '<', $lobbyCutoff->max($playingCutoff))
      ->orderBy('id')
      ->pluck('id');

    $freed = 0;

    foreach ($candidates as $seatId) {
      if ($this->releaseIfIdle($seatId, $lobbyCutoff, $playingCutoff)) {
        $freed++;
      }
    }

    return $freed;
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
   * left, or the table may have started or finished a board.
   */
  private function releaseIfIdle(int $seatId, Carbon $lobbyCutoff, Carbon $playingCutoff): bool
  {
    return DB::transaction(function () use ($seatId, $lobbyCutoff, $playingCutoff) {
      $tableId = TableSeat::whereKey($seatId)->value('table_id');

      if ($tableId === null) {
        return false;
      }

      $table = Table::whereKey($tableId)->lockForUpdate()->first();
      $seat = TableSeat::whereKey($seatId)->where('table_id', $tableId)->with('user')->first();

      if ($table === null || $seat === null) {
        return false;
      }

      $cutoff = $this->boardSelection->openPlaying($table) !== null ? $playingCutoff : $lobbyCutoff;

      if ($seat->last_seen_at->gte($cutoff)) {
        return false;
      }

      $this->remove($table, $seat->user);

      return true;
    });
  }
}

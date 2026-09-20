<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\Exceptions\SeatUnavailableException;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class TableSeatService
{
  public function __construct(private BoardSelectionService $boardSelection)
  {
  }

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
   * Filling the last seat deals the table a board and opens its playing, so
   * this mutates `$table` (`board_id`).
   *
   * @throws SeatUnavailableException
   */
  public function seat(Table $table, User $user, string $seat, ?User $by = null): TableSeat
  {
    $self = $by === null || $by->id === $user->id;

    try {
      return DB::transaction(function () use ($table, $user, $seat, $self) {
        if (!in_array($seat, Seats::SEATS, true)) {
          throw new SeatUnavailableException("Unknown seat '$seat'.");
        }

        $held = $user->seats()->first();

        if ($held !== null && !$self) {
          throw new SeatUnavailableException('That user is already seated at a table.');
        }

        // both tables are locked up front, lowest id first, so two players
        // swapping tables at the same moment can't deadlock each other
        $this->lockTables($table, $held?->table_id);

        if ($table->seats()->where('seat', $seat)->exists()) {
          throw new SeatUnavailableException("Seat $seat is already taken.");
        }

        // changing seat at the table they already sit at: move the row rather
        // than leave and rejoin, which would delete the table under them if
        // they were its only player, and would lose their place in the
        // join order the moderator handover reads
        if ($held !== null && (int) $held->table_id === (int) $table->getKey()) {
          $held->update(['seat' => $seat]);

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

        $seatRow = $table->seats()->create([
          'user_id' => $user->id,
          'seat' => $seat,
        ]);

        // the fourth player to sit down starts the board
        $this->boardSelection->startPlayingIfFull($table);

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
   * deletes it. If anyone is left and the leaver was the moderator, the role
   * passes to the creator when they are still seated, otherwise to the player
   * who joined earliest — a table is only ever managed by one person, and
   * `TablePolicy::manage` already treats a seated creator as that person.
   * `created_by` never moves: it is what the per-creator active-table limit
   * counts.
   *
   * A playing that was under way is dropped: the four who started it are no
   * longer the four sitting there. See `BoardSelectionService::abandonPlaying`.
   *
   * Mutates `$table` (moderator handover, `board_id`) and returns true if the
   * table was deleted.
   *
   * @throws SeatUnavailableException
   */
  public function remove(Table $table, User $user, ?User $by = null): bool
  {
    return DB::transaction(function () use ($table, $user, $by) {
      // serialize seat changes on this table
      Table::whereKey($table->getKey())->lockForUpdate()->firstOrFail();

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

      // the creator if they are still here, else the earliest joiner still at
      // the table, if any
      $next = $table->seats()->where('user_id', $table->created_by)->first()
        ?? $table->seats()->orderBy('created_at')->orderBy('id')->first();

      if ($next === null) {
        $table->delete();

        return true;
      }

      if ((int) $table->moderated_by === $user->id) {
        $table->update(['moderated_by' => $next->user_id]);
      }

      return false;
    });
  }
}

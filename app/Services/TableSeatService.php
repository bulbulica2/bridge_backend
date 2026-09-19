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
  /**
   * Seat a user at a table, holding every availability check.
   *
   * `$by` is who asked, when that isn't `$user` themselves (a manager seating
   * another player); it only changes the wording of the error.
   *
   * @throws SeatUnavailableException
   */
  public function seat(Table $table, User $user, string $seat, ?User $by = null): TableSeat
  {
    try {
      return DB::transaction(function () use ($table, $user, $seat, $by) {
        // serialize seat changes on this table
        $table = Table::whereKey($table->getKey())->lockForUpdate()->firstOrFail();

        if (!in_array($seat, Seats::SEATS, true)) {
          throw new SeatUnavailableException("Unknown seat '$seat'.");
        }

        if ($table->seats()->where('seat', $seat)->exists()) {
          throw new SeatUnavailableException("Seat $seat is already taken.");
        }

        if ($user->seats()->exists()) {
          throw new SeatUnavailableException(
            $by === null || $by->id === $user->id
              ? 'You are already seated at a table.'
              : 'That user is already seated at a table.'
          );
        }

        return $table->seats()->create([
          'user_id' => $user->id,
          'seat' => $seat,
        ]);
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
   * Mutates `$table` (moderator handover) and returns true if the table was
   * deleted.
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

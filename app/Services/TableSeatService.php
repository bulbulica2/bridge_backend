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
   * @throws SeatUnavailableException
   */
  public function seat(Table $table, User $user, string $seat): TableSeat
  {
    try {
      return DB::transaction(function () use ($table, $user, $seat) {
        // serialize seat changes on this table
        $table = Table::whereKey($table->getKey())->lockForUpdate()->firstOrFail();

        if (!in_array($seat, Seats::SEATS, true)) {
          throw new SeatUnavailableException("Unknown seat '$seat'.");
        }

        if ($table->seats()->where('seat', $seat)->exists()) {
          throw new SeatUnavailableException("Seat $seat is already taken.");
        }

        if ($user->seats()->exists()) {
          throw new SeatUnavailableException('You are already seated at a table.');
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
   * Free the seat a user holds at a table.
   *
   * A table lives only while somebody sits at it, so the last player to leave
   * deletes it. If anyone is left and the leaver was the moderator, the role
   * passes to the player who joined earliest. `created_by` never moves: it is
   * what the per-creator active-table limit counts.
   *
   * Mutates `$table` (moderator handover) and returns true if the table was
   * deleted.
   *
   * @throws SeatUnavailableException
   */
  public function leave(Table $table, User $user): bool
  {
    return DB::transaction(function () use ($table, $user) {
      // serialize seat changes on this table
      Table::whereKey($table->getKey())->lockForUpdate()->firstOrFail();

      $seat = $table->seats()->where('user_id', $user->id)->first();

      if ($seat === null) {
        throw new SeatUnavailableException('You are not seated at this table.');
      }

      $seat->delete();

      // earliest joiner still at the table, if any
      $next = $table->seats()->orderBy('created_at')->orderBy('id')->first();

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

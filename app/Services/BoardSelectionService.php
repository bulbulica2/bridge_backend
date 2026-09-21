<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\auxiliary\Vulnerability;
use App\Models\Board;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\Table;
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
 * That is why `POST /tables` still leaves `tables.board_id` null — the board
 * is dealt by whoever fills the last seat.
 */
class BoardSelectionService
{
  /**
   * Deal a board to a table that has just filled up, and open the playing.
   *
   * Returns null while the table is not yet full, or when a playing is
   * already open. Mutates `$table` (`board_id`).
   */
  public function startPlayingIfFull(Table $table): ?BoardTable
  {
    $seats = $table->seats()->get();

    if ($seats->count() < count(Seats::SEATS)) {
      return null;
    }

    $open = $this->openPlaying($table);

    if ($open !== null) {
      return $open;
    }

    $board = $this->selectBoard($table, $seats);

    $table->update(['board_id' => $board->id]);

    $playing = BoardTable::create([
      'board_id' => $board->id,
      'table_id' => $table->id,
      'started_at' => now(),
    ]);

    foreach ($seats as $seat) {
      $playing->seats()->create([
        'user_id' => $seat->user_id,
        'seat' => $seat->seat,
      ]);
    }

    return $playing;
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
   * Its calls and cards are deleted, though: the board is never resumed, and
   * once detached they would no longer go with the table the way every other
   * playing's logs do (`Table::deleting`).
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
        'Cannot deal a board: the cards table holds ' . count($cardIds) . ' rows, expected 52. Run CardSeeder.'
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
   * The board-selection rule from `bridge_docs/GAME-RULES.md` §8: boards
   * should rotate as much as possible, so players never recognise a deal.
   *
   * @param  Collection<int, \App\Models\TableSeat>  $seats
   */
  private function selectBoard(Table $table, Collection $seats): Board
  {
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

  private function openPlaying(Table $table): ?BoardTable
  {
    return BoardTable::query()
      ->where('table_id', $table->id)
      ->whereNull('finished_at')
      ->first();
  }
}

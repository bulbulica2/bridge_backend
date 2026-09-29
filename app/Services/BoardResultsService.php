<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\Http\Resources\PlayingResource;
use App\Http\Resources\UserResource;
use App\Models\Board;
use App\Models\BoardTable;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Finished playings read back after the fact: a board's results at every
 * table that played it, with matchpoints (`GAME-RULES.md` §6), and one
 * player's history.
 *
 * Both read `board_table` and its `board_table_seats` snapshot, which outlive
 * the table, so neither loses anything when a table is deleted.
 */
class BoardResultsService
{
  public const HISTORY_PER_PAGE = 20;

  /**
   * Whether the user has finished this board at some table: the rule for
   * seeing its results and its deal, which would otherwise give it away to
   * somebody who may still be dealt it. Leaving mid-board doesn't count.
   */
  public function hasFinished(User $user, Board $board): bool
  {
    return BoardTable::query()
      ->where('board_id', $board->getKey())
      ->whereNotNull('finished_at')
      ->whereHas('seats', fn ($query) => $query->where('user_id', $user->getKey()))
      ->exists();
  }

  /**
   * Every finished playing of the board, best N-S score first, each with
   * its matchpoints. Worked out on every read, since each new playing
   * changes everyone's matchpoints.
   *
   * @return array<string, mixed>
   */
  public function results(Board $board): array
  {
    $playings = BoardTable::query()
      ->where('board_id', $board->getKey())
      ->whereNotNull('finished_at')
      ->with(['seats.user', 'contractBid'])
      ->orderByDesc('score')
      ->orderBy('finished_at')
      ->orderBy('id')
      ->get();

    $matchpoints = ScoringService::matchpoints($playings->map(fn ($playing) => (int) $playing->score)->all());
    $top = ScoringService::matchpointTop($playings->count());

    return [
      'board' => self::board($board),
      'top' => $top,
      'results' => $playings->values()->map(fn (BoardTable $playing, int $i) => [
        'playing_id' => $playing->id,
        // null once the table has been deleted
        'table_id' => $playing->table_id,
        'players' => self::players($playing),
        ...PlayingResource::result($playing),
        'matchpoints' => ['ns' => $matchpoints[$i], 'ew' => $top - $matchpoints[$i]],
        'finished_at' => $playing->finished_at,
      ])->all(),
    ];
  }

  /**
   * The user's finished playings, latest first, one page at a time: the
   * board, their seat and partner, the contract and the score.
   */
  public function history(User $user, int $perPage = self::HISTORY_PER_PAGE): LengthAwarePaginator
  {
    return BoardTable::query()
      ->whereNotNull('finished_at')
      ->whereHas('seats', fn ($query) => $query->where('user_id', $user->getKey()))
      ->with(['board', 'seats.user', 'contractBid'])
      ->orderByDesc('finished_at')
      ->orderByDesc('id')
      ->paginate($perPage)
      ->through(function (BoardTable $playing) use ($user) {
        $seat = $playing->seats->firstWhere('user_id', $user->getKey())->seat;
        $partner = $playing->seats->firstWhere('seat', Seats::partner($seat))?->user;
        $result = PlayingResource::result($playing);

        return [
          'playing_id' => $playing->id,
          'table_id' => $playing->table_id,
          'board' => self::board($playing->board),
          'seat' => $seat,
          'partner' => $partner === null ? null : new UserResource($partner),
          ...$result,
          // the same score from the user's own side
          'score' => CardPlayService::side($seat) === 'ns' ? $result['score_ns'] : -$result['score_ns'],
          'finished_at' => $playing->finished_at,
        ];
      });
  }

  /**
   * @return array{id: int, number: int, dealer: string, vulnerable: string}
   */
  private static function board(Board $board): array
  {
    return [
      'id' => $board->id,
      'number' => $board->number,
      'dealer' => $board->dealer,
      'vulnerable' => $board->vulnerable,
    ];
  }

  /**
   * Seat → public profile, from the playing's seat snapshot.
   *
   * @return array<string, UserResource|null>
   */
  private static function players(BoardTable $playing): array
  {
    $players = [];

    foreach (Seats::SEATS as $seat) {
      $user = $playing->seats->firstWhere('seat', $seat)?->user;
      $players[$seat] = $user === null ? null : new UserResource($user);
    }

    return $players;
  }
}

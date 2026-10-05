<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\Http\Resources\PlayingResource;
use App\Http\Resources\UserResource;
use App\Models\Board;
use App\Models\BoardTable;
use App\Models\TableSet;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Finished playings read back after the fact: a board's results at every
 * table that played it, with matchpoints (`GAME-RULES.md` §6), a set's
 * results, and one player's history.
 *
 * They read `board_table`, its `board_table_seats` snapshot and `table_sets`,
 * which all outlive the table, so nothing is lost when a table is deleted.
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
   * Whether the user may see a set's results: one of its four players, or
   * somebody who has finished every board the set finished, at any table.
   * Anyone else may still be dealt one of them (`BoardPolicy::view`).
   */
  public function maySeeSet(User $user, TableSet $set): bool
  {
    if ($set->hasPlayer($user->getKey())) {
      return true;
    }

    $boards = $set->playings()->whereNotNull('finished_at')->with('board')->get()->pluck('board');

    return $boards->isNotEmpty() && $boards->every(fn (Board $board) => $this->hasFinished($user, $board));
  }

  /**
   * A set's results: its players, each of its finished boards in order with
   * its result and matchpoints (against every table that has played the
   * board, worked out now like `results()`), the totals per side and the
   * winner.
   *
   * The winner is the side with the higher total score, the boards'
   * `score_ns` added up: matchpoints only compare a pair with the other
   * tables, and a board played at one table has none to give. Null while
   * the set is unfinished, when it was abandoned, and on a tie. A side a
   * robot played for after its human walked out (`replaced`) can still win
   * it.
   *
   * @return array<string, mixed>
   */
  public function set(TableSet $set): array
  {
    $set->load([
      'seats.user',
      'playings' => fn ($playings) => $playings->whereNotNull('finished_at')->with(['board', 'contractBid']),
    ]);

    $boards = $set->playings->map(function (BoardTable $playing) {
      $matchpoints = $this->matchpointsOf($playing);

      return [
        'position' => $playing->set_position,
        'playing_id' => $playing->id,
        'board' => self::board($playing->board),
        ...PlayingResource::result($playing),
        'top' => $matchpoints['top'],
        'matchpoints' => ['ns' => $matchpoints['ns'], 'ew' => $matchpoints['top'] - $matchpoints['ns']],
      ];
    })->values();

    $scoreNs = (int) $boards->sum('score_ns');
    $top = (int) $boards->sum('top');
    $matchpointsNs = (int) $boards->sum('matchpoints.ns');

    $players = [];

    foreach (Seats::SEATS as $seat) {
      $user = $set->seats->firstWhere('seat', $seat)?->user;
      $players[$seat] = $user === null ? null : new UserResource($user);
    }

    return [
      'id' => $set->id,
      'number' => $set->number,
      // null once the table has been deleted
      'table_id' => $set->table_id,
      'of' => $set->size,
      // boards dealt so far, one abandoned mid-play included
      'boards_dealt' => (int) $set->playings()->reorder()->max('set_position'),
      'started_at' => $set->started_at,
      'finished_at' => $set->finished_at,
      'finished' => $set->isFinished(),
      'ended' => $set->ended,
      'players' => $players,
      'replaced' => $set->replacements(),
      'minutes' => $set->minutes,
      'time_left' => PlayingResource::timeLeft($set),
      'time_used' => self::timeUsed($set),
      'boards' => $boards->all(),
      'totals' => [
        'score' => ['ns' => $scoreNs, 'ew' => -$scoreNs],
        'matchpoints' => ['ns' => $matchpointsNs, 'ew' => $top - $matchpointsNs],
        'top' => $top,
      ],
      'winner' => match (true) {
        $set->ended !== TableSet::ENDED_COMPLETED, $scoreNs === 0 => null,
        default => $scoreNs > 0 ? 'NS' : 'EW',
      },
    ];
  }

  /**
   * The seconds each seat's human used of their time bank for the set,
   * `{N, E, S, W}`: up to the board's last move, or, for a seat a robot
   * took over, up to the takeover. Null for a seat a robot or an admin
   * played from the start.
   *
   * @return array<string, int|null>
   */
  private static function timeUsed(TableSet $set): array
  {
    $used = [];

    foreach (Seats::SEATS as $seat) {
      $row = $set->seats->firstWhere('seat', $seat);
      $used[$seat] = $row?->time_left_ms === null ? null : $set->minutes * 60 - intdiv($row->time_left_ms, 1000);
    }

    return $used;
  }

  /**
   * One finished playing's N-S matchpoints against every finished playing
   * of its board, and the top.
   *
   * @return array{ns: int, top: int}
   */
  private function matchpointsOf(BoardTable $playing): array
  {
    $scores = BoardTable::query()
      ->where('board_id', $playing->board_id)
      ->whereNotNull('finished_at')
      ->orderBy('id')
      ->pluck('score', 'id');

    $matchpoints = ScoringService::matchpoints($scores->map(fn ($score) => (int) $score)->values()->all());

    return [
      'ns' => $matchpoints[$scores->keys()->search($playing->id)],
      'top' => ScoringService::matchpointTop($scores->count()),
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
      ->with(['board', 'tableSet', 'seats.user', 'contractBid'])
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
          // to group the history by set (GET /sets/{set} has its results)
          'set' => $playing->tableSet === null ? null : [
            'id' => $playing->tableSet->id,
            'number' => $playing->tableSet->number,
            'board' => $playing->set_position,
            'of' => $playing->tableSet->size,
          ],
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

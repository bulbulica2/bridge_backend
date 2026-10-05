<?php

namespace App\Services;

use App\Models\BoardTable;
use App\Models\TableSet;
use App\Models\TableSetSeat;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * How a player plays, read back from what outlives the tables: their
 * finished boards (`board_table_seats`), their sets (`table_set_seats`)
 * and the sets they walked out on.
 *
 * Matchpoints change as more tables finish a board, so everything is
 * worked out on every read and nothing is stored: a handful of queries,
 * whatever the player's history. A percentage with nothing to average is
 * null.
 */
class PlayerStatsService
{
  /** Why a set a player abandoned ended without them, beyond `TableSetSeat::REASONS`: it ended `abandoned`. */
  public const LEFT = 'left';

  /**
   * @return array<string, mixed>
   */
  public function stats(User $user): array
  {
    // the boards they finished in their own seat: a robot that took a hand
    // over finishes it in its own name
    $boards = BoardTable::query()
      ->join('board_table_seats', 'board_table_seats.board_table_id', '=', 'board_table.id')
      ->where('board_table_seats.user_id', $user->id)
      ->whereNotNull('board_table.finished_at')
      ->get(['board_table.id', 'board_table.board_id', 'board_table_seats.seat']);

    // the sets they saw through: a player a robot took over from is no
    // longer the seat's user_id
    $sets = TableSetSeat::query()
      ->join('table_sets', 'table_sets.id', '=', 'table_set_seats.table_set_id')
      ->where('table_set_seats.user_id', $user->id)
      ->where('table_sets.ended', TableSet::ENDED_COMPLETED)
      ->get(['table_set_seats.table_set_id', 'table_set_seats.seat']);

    $setPlayings = BoardTable::query()
      ->whereIn('table_set_id', $sets->pluck('table_set_id'))
      ->whereNotNull('finished_at')
      ->get(['id', 'board_id', 'table_set_id', 'score']);

    $matchpoints = $this->matchpoints($boards->pluck('board_id')->merge($setPlayings->pluck('board_id'))->unique());

    return [
      'user_id' => $user->id,
      'boards' => $this->boards($boards, $matchpoints),
      'sets' => $this->sets($sets, $setPlayings->groupBy('table_set_id'), $matchpoints),
      'leaving' => $this->leaving($user, $sets->count()),
    ];
  }

  /**
   * @param  Collection<int, BoardTable>  $boards  with `seat`
   * @param  array<int, array{ns: int, top: int}>  $matchpoints
   * @return array<string, mixed>
   */
  private function boards(Collection $boards, array $matchpoints): array
  {
    $percents = $boards
      ->filter(fn ($board) => $matchpoints[$board->id]['top'] > 0)
      ->map(fn ($board) => self::percent(self::ours($matchpoints[$board->id], $board->seat), $matchpoints[$board->id]['top']))
      ->values();

    $won = $percents->filter(fn (float $percent) => $percent > 50)->count();

    return [
      'played' => $boards->count(),
      'compared' => $percents->count(),
      'won' => $won,
      'win_rate' => self::rate($won, $percents->count()),
      'average_percent' => self::average($percents),
    ];
  }

  /**
   * Won as `GET /sets/{set}`'s `winner`: the higher total score. The
   * percentage is the side's matchpoints over the set's top, for a set
   * with any board some other table has played.
   *
   * @param  Collection<int, TableSetSeat>  $sets
   * @param  Collection<int, Collection<int, BoardTable>>  $playings  by set
   * @param  array<int, array{ns: int, top: int}>  $matchpoints
   * @return array<string, mixed>
   */
  private function sets(Collection $sets, Collection $playings, array $matchpoints): array
  {
    $won = 0;
    $percents = collect();

    foreach ($sets as $set) {
      $boards = $playings->get($set->table_set_id, collect());
      $score = (int) $boards->sum('score') * (CardPlayService::side($set->seat) === 'ns' ? 1 : -1);
      $won += $score > 0 ? 1 : 0;

      $top = $boards->sum(fn ($board) => $matchpoints[$board->id]['top']);

      if ($top > 0) {
        $percents->push(self::percent($boards->sum(fn ($board) => self::ours($matchpoints[$board->id], $set->seat)), $top));
      }
    }

    return [
      'played' => $sets->count(),
      'won' => $won,
      'win_rate' => self::rate($won, $sets->count()),
      'average_percent' => self::average($percents),
    ];
  }

  /**
   * Sets walked out on: each one a robot took their seat in, whatever
   * became of the set, and each one that ended `abandoned` as they went
   * (`ended_by`, unrecorded for sets that ended before it was kept).
   *
   * @return array<string, mixed>
   */
  private function leaving(User $user, int $setsPlayed): array
  {
    $byReason = array_fill_keys([...TableSetSeat::REASONS, self::LEFT], 0);

    $replaced = TableSetSeat::query()
      ->where('replaced_user_id', $user->id)
      ->groupBy('replaced_reason')
      ->selectRaw('replaced_reason, count(*) as walked_out')
      ->pluck('walked_out', 'replaced_reason');

    foreach ($replaced as $reason => $count) {
      $byReason[$reason] = (int) $count;
    }

    $byReason[self::LEFT] = TableSet::query()
      ->where('ended', TableSet::ENDED_ABANDONED)
      ->where('ended_by', $user->id)
      ->count();

    $abandoned = array_sum($byReason);

    return [
      'abandoned' => $abandoned,
      'abandoned_by_reason' => $byReason,
      'left_rate' => self::rate($abandoned, $setsPlayed + $abandoned),
    ];
  }

  /**
   * Each finished playing of these boards → its N-S matchpoints against
   * every finished playing of its board, and the top.
   *
   * @param  Collection<int, int>  $boardIds
   * @return array<int, array{ns: int, top: int}>
   */
  private function matchpoints(Collection $boardIds): array
  {
    $byPlaying = [];

    BoardTable::query()
      ->whereIn('board_id', $boardIds->values())
      ->whereNotNull('finished_at')
      ->get(['id', 'board_id', 'score'])
      ->groupBy('board_id')
      ->each(function (Collection $playings) use (&$byPlaying) {
        $points = ScoringService::matchpoints($playings->map(fn ($playing) => (int) $playing->score)->all());
        $top = ScoringService::matchpointTop($playings->count());

        foreach ($playings->values() as $i => $playing) {
          $byPlaying[$playing->id] = ['ns' => $points[$i], 'top' => $top];
        }
      });

    return $byPlaying;
  }

  /**
   * `$seat`'s side's share of a board's matchpoints.
   *
   * @param  array{ns: int, top: int}  $matchpoints
   */
  private static function ours(array $matchpoints, string $seat): int
  {
    return CardPlayService::side($seat) === 'ns' ? $matchpoints['ns'] : $matchpoints['top'] - $matchpoints['ns'];
  }

  private static function percent(int $points, int $top): float
  {
    return 100 * $points / $top;
  }

  private static function rate(int $count, int $of): ?float
  {
    return $of === 0 ? null : round($count / $of, 4);
  }

  /**
   * @param  Collection<int, float>  $percents
   */
  private static function average(Collection $percents): ?float
  {
    return $percents->isEmpty() ? null : round($percents->avg(), 2);
  }
}

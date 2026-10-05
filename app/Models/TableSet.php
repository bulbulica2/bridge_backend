<?php

namespace App\Models;

use App\auxiliary\Seats;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A set of boards (`bridge.set_size`) the same four seats play in a row at
 * one table. Everyone's Start opens it, Next deals its boards one after
 * another, and it is over once its last board is finished — or earlier, if
 * one of its four leaves the table (`abandoned`). A player who walks out on
 * it (their turn clock runs out, they move tables, are kicked while away or
 * banned) doesn't end it: a robot takes their seat for the rest of it
 * (`TableSetSeat::replaced_user_id`). Like the playings in it, it outlives
 * its table (`table_id` goes null).
 */
class TableSet extends Model
{
  public const ENDED_COMPLETED = 'completed';

  /** One of its four left before the last board was finished. */
  public const ENDED_ABANDONED = 'abandoned';

  public const ENDINGS = [self::ENDED_COMPLETED, self::ENDED_ABANDONED];

  protected $fillable = [
    'table_id',
    'number',
    'size',
    'started_at',
    'finished_at',
    'ended',
    'ended_by',
  ];

  protected function casts(): array
  {
    return [
      'number' => 'integer',
      'size' => 'integer',
      'started_at' => 'datetime',
      'finished_at' => 'datetime',
      'ended_by' => 'integer',
    ];
  }

  public function seats(): HasMany
  {
    return $this->hasMany(TableSetSeat::class);
  }

  /**
   * The boards dealt in this set, in the order they were dealt.
   */
  public function playings(): HasMany
  {
    return $this->hasMany(BoardTable::class)->orderBy('set_position');
  }

  public function isFinished(): bool
  {
    return $this->finished_at !== null;
  }

  /**
   * End the set, unless it already has. `completed` once its last board is
   * finished (`BoardTable::finish()`), `abandoned` when one of its four
   * leaves before that, by `$endedBy` (the player who left, if the set
   * is theirs to answer for: `ended_by`).
   */
  public function end(string $ended, ?int $endedBy = null): void
  {
    if ($this->isFinished()) {
      return;
    }

    $this->update(['finished_at' => now(), 'ended' => $ended, 'ended_by' => $endedBy]);
  }

  /**
   * Whether `$userId` is one of the set's four players, or played in it
   * until a robot took their seat.
   */
  public function hasPlayer(int $userId): bool
  {
    return $this->seats()->where(fn ($seat) => $seat->where('user_id', $userId)->orWhere('replaced_user_id', $userId))->exists();
  }

  /**
   * The players a robot took over from, in seat order: `{seat, user_id,
   * reason}` (`TableSetSeat::REASONS`), the robot being the seat's player
   * now. Empty while all four are the ones who opened the set.
   *
   * @return list<array{seat: string, user_id: int, reason: string}>
   */
  public function replacements(): array
  {
    return $this->seats
      ->whereNotNull('replaced_user_id')
      ->sortBy(fn (TableSetSeat $seat) => array_search($seat->seat, Seats::SEATS, true))
      ->map(fn (TableSetSeat $seat) => [
        'seat' => $seat->seat,
        'user_id' => (int) $seat->replaced_user_id,
        'reason' => $seat->replaced_reason,
      ])
      ->values()
      ->all();
  }
}

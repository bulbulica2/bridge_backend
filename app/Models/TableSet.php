<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A set of boards (`bridge.set_size`) the same four players play in a row at
 * one table. Everyone's Start opens it, Next deals its boards one after
 * another, and it is over once its last board is finished — or earlier, if
 * one of its four leaves the table (`abandoned`) or is away from it too long
 * (`forfeit`). Like the playings in it, it outlives its
 * table (`table_id` goes null).
 */
class TableSet extends Model
{
  public const ENDED_COMPLETED = 'completed';

  /** One of its four left before the last board was finished. */
  public const ENDED_ABANDONED = 'abandoned';

  /** A side lost it: one of its players was away too long, or moved to another table, mid-set. */
  public const ENDED_FORFEIT = 'forfeit';

  public const ENDINGS = [self::ENDED_COMPLETED, self::ENDED_ABANDONED, self::ENDED_FORFEIT];

  public const SIDES = ['NS', 'EW'];

  protected $fillable = [
    'table_id',
    'number',
    'size',
    'started_at',
    'finished_at',
    'ended',
    'forfeited_by',
  ];

  protected function casts(): array
  {
    return [
      'number' => 'integer',
      'size' => 'integer',
      'started_at' => 'datetime',
      'finished_at' => 'datetime',
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
   * leaves before that.
   */
  public function end(string $ended): void
  {
    if ($this->isFinished()) {
      return;
    }

    $this->update(['finished_at' => now(), 'ended' => $ended]);
  }

  /**
   * End the set as lost by `$side` (`NS` or `EW`), whose player went away
   * from it, unless it is over already.
   */
  public function forfeit(string $side): void
  {
    if ($this->isFinished()) {
      return;
    }

    $this->update(['finished_at' => now(), 'ended' => self::ENDED_FORFEIT, 'forfeited_by' => $side]);
  }

  /**
   * Whether `$userId` is one of the set's four players.
   */
  public function hasPlayer(int $userId): bool
  {
    return $this->seats()->where('user_id', $userId)->exists();
  }
}

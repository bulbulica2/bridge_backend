<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A set of boards (`bridge.set_size`) the same four players play in a row at
 * one table. Everyone's Start opens it, Next deals its boards one after
 * another, and it is over once its last board is finished — or earlier, if
 * one of its four leaves the table (`abandoned`) or costs their side the set
 * (`forfeit`, see `FORFEIT_REASONS`). Like the playings in it, it outlives its
 * table (`table_id` goes null).
 */
class TableSet extends Model
{
  public const ENDED_COMPLETED = 'completed';

  /** One of its four left before the last board was finished. */
  public const ENDED_ABANDONED = 'abandoned';

  /** A side lost it: see `FORFEIT_REASONS` for how. */
  public const ENDED_FORFEIT = 'forfeit';

  public const ENDINGS = [self::ENDED_COMPLETED, self::ENDED_ABANDONED, self::ENDED_FORFEIT];

  /** The player on turn let their turn clock run out. */
  public const FORFEIT_TURN_TIMEOUT = 'turn_timeout';

  /** The player on turn let their turn clock run out while away (gone quiet, or pressed Leave). */
  public const FORFEIT_AWAY = 'away';

  /** A player walked out on the set for another table. */
  public const FORFEIT_MOVED = 'moved';

  /** A player was kicked while away, or banned. */
  public const FORFEIT_KICKED = 'kicked';

  public const FORFEIT_REASONS = [self::FORFEIT_TURN_TIMEOUT, self::FORFEIT_AWAY, self::FORFEIT_MOVED, self::FORFEIT_KICKED];

  public const SIDES = ['NS', 'EW'];

  protected $fillable = [
    'table_id',
    'number',
    'size',
    'started_at',
    'finished_at',
    'ended',
    'forfeited_by',
    'forfeit_reason',
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
   * End the set as lost by `$side` (`NS` or `EW`), one of whose players
   * cost them it for `$reason` (one of `FORFEIT_REASONS`), unless it is
   * over already.
   */
  public function forfeit(string $side, string $reason): void
  {
    if ($this->isFinished()) {
      return;
    }

    $this->update(['finished_at' => now(), 'ended' => self::ENDED_FORFEIT, 'forfeited_by' => $side, 'forfeit_reason' => $reason]);
  }

  /**
   * Whether `$userId` is one of the set's four players.
   */
  public function hasPlayer(int $userId): bool
  {
    return $this->seats()->where('user_id', $userId)->exists();
  }
}

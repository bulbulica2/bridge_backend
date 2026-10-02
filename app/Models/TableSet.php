<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A set of boards (`bridge.set_size`) the same four players play in a row at
 * one table. Everyone's Start opens it, Next deals its boards one after
 * another, and it is over once its last board is finished — or earlier, if
 * one of its four leaves the table. Like the playings in it, it outlives its
 * table (`table_id` goes null).
 */
class TableSet extends Model
{
  public const ENDED_COMPLETED = 'completed';

  /** One of its four left before the last board was finished. */
  public const ENDED_ABANDONED = 'abandoned';

  /** A side lost it by going away mid-set (planned, #76: nothing ends a set this way yet). */
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

  public function table(): BelongsTo
  {
    return $this->belongsTo(Table::class);
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
   * Whether `$userId` is one of the set's four players.
   */
  public function hasPlayer(int $userId): bool
  {
    return $this->seats()->where('user_id', $userId)->exists();
  }
}

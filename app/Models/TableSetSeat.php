<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seat of a set: who plays it (`user_id`) and, once a robot has taken
 * it over mid-set, the human it took over from (`replaced_user_id`), why
 * (`replaced_reason`, one of `REASONS`) and when (`replaced_at`).
 *
 * `time_left_ms` is what is left of its human's time bank for the set, as
 * of the playing's `turn_started_at` (`BoardTable::chargeTurn()`); null for
 * a robot or an admin, who have none. A robot taking the seat over leaves
 * it as its human left it.
 */
class TableSetSeat extends Model
{
  /** Their turn clock ran out. */
  public const REASON_TURN_TIMEOUT = 'turn_timeout';

  /** Their time for the whole set ran out (`time_left_ms`). */
  public const REASON_SET_TIME = 'set_time';

  /** Their turn clock ran out while they were away (gone quiet, or after a Leave). */
  public const REASON_AWAY = 'away';

  /** They walked out on the set for another table. */
  public const REASON_MOVED = 'moved';

  /** They were kicked while away, or banned. */
  public const REASON_KICKED = 'kicked';

  public const REASONS = [self::REASON_TURN_TIMEOUT, self::REASON_SET_TIME, self::REASON_AWAY, self::REASON_MOVED, self::REASON_KICKED];

  protected $fillable = [
    'table_set_id',
    'user_id',
    'seat',
    'replaced_user_id',
    'replaced_reason',
    'replaced_at',
    'time_left_ms',
  ];

  protected function casts(): array
  {
    return [
      'replaced_at' => 'datetime',
      'time_left_ms' => 'integer',
    ];
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }
}

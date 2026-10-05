<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seat of a set: who plays it (`user_id`) and, once a robot has taken
 * it over mid-set, the human it took over from (`replaced_user_id`), why
 * (`replaced_reason`, one of `REASONS`) and when (`replaced_at`).
 */
class TableSetSeat extends Model
{
  /** Their turn clock ran out. */
  public const REASON_TURN_TIMEOUT = 'turn_timeout';

  /** Their turn clock ran out while they were away (gone quiet, or after a Leave). */
  public const REASON_AWAY = 'away';

  /** They walked out on the set for another table. */
  public const REASON_MOVED = 'moved';

  /** They were kicked while away, or banned. */
  public const REASON_KICKED = 'kicked';

  public const REASONS = [self::REASON_TURN_TIMEOUT, self::REASON_AWAY, self::REASON_MOVED, self::REASON_KICKED];

  protected $fillable = [
    'table_set_id',
    'user_id',
    'seat',
    'replaced_user_id',
    'replaced_reason',
    'replaced_at',
  ];

  protected function casts(): array
  {
    return [
      'replaced_at' => 'datetime',
    ];
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }
}

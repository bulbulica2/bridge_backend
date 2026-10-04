<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin keeping a user away from the game until `until`, for `reason`.
 * It is in force while not lifted and `until` is still ahead (active()): it
 * ends by itself, with nothing to run. Rows are kept as the user's history.
 */
class UserBan extends Model
{
  /** The longest ban an admin may give, in days. */
  public const MAX_DAYS = 365;

  /**
   * The longest `reason`, in characters: `UserBanned` carries it, and must
   * fit in 10 KB (see `User::NAME_MAX`).
   */
  public const REASON_MAX = 500;

  protected $fillable = [
    'user_id',
    'banned_by',
    'reason',
    'banned_at',
    'until',
    'lifted_at',
    'lifted_by',
  ];

  protected function casts(): array
  {
    return [
      'banned_at' => 'datetime',
      'until' => 'datetime',
      'lifted_at' => 'datetime',
    ];
  }

  /**
   * The bans in force now: not lifted, and not yet run out.
   */
  public function scopeActive(Builder $query): void
  {
    $query->whereNull('lifted_at')->where('until', '>', now());
  }

  public function bannedBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'banned_by');
  }

  public function liftedBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'lifted_by');
  }

  /**
   * What the banned user is told every time a game action is refused.
   */
  public function message(): string
  {
    return "You are banned until {$this->until->format('j M Y')}: {$this->reason}";
  }

  /**
   * The ban as the banned user sees it (`GET /api/user`'s `ban`, the
   * `UserBanned` event, a refused game action's `data.ban`): nothing about
   * which admin did it.
   *
   * @return array{reason: string, until: string, banned_at: string}
   */
  public function toOwnArray(): array
  {
    return [
      'reason' => $this->reason,
      'until' => $this->until->toJSON(),
      'banned_at' => $this->banned_at->toJSON(),
    ];
  }
}

<?php

namespace App\Models;

use App\auxiliary\Seats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Table extends Model
{
  use HasFactory;

  /**
   * How many active tables one user may have created at a time.
   */
  public const MAX_ACTIVE_PER_CREATOR = 3;

  protected static function booted(): void
  {
    // a finished playing keeps its call-by-call and card-by-card logs, so
    // the board can be reviewed after the table is gone (GET
    // /playings/{playing}); an unfinished one has no result to review and
    // loses them. remove() has already detached (and discarded) an
    // unfinished playing by the time the last player deletes the table, so
    // this only guards against a table deleted some other way
    static::deleting(function (Table $table) {
      $table->boardPlays()
        ->whereNull('finished_at')
        ->each(fn (BoardTable $playing) => $playing->discardLogs());
    });
  }

  protected $fillable = [
    'name',
    'created_by',
    'moderated_by',
    'board_id',
    'unattended_since',
  ];

  protected function casts(): array
  {
    return [
      'unattended_since' => 'datetime',
    ];
  }

  /**
   * A table is active while somebody still sits at it. The last player to
   * leave deletes it, so an inactive table only exists mid-transaction.
   */
  public function scopeActive(Builder $query): void
  {
    $query->whereHas('seats');
  }

  /**
   * A table with a human at it. Robots alone keep a table alive (it is
   * unattended) but don't count towards its creator's limit.
   */
  public function scopeAttended(Builder $query): void
  {
    $query->whereHas('seats.user', fn (Builder $user) => $user->where('is_robot', false));
  }

  /**
   * Seats nobody sits in, in N/E/S/W order.
   *
   * @return list<string>
   */
  public function freeSeats(): array
  {
    return array_values(array_diff(Seats::SEATS, $this->seats->pluck('seat')->all()));
  }

  public function creator(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by');
  }

  public function moderator(): BelongsTo
  {
    return $this->belongsTo(User::class, 'moderated_by');
  }

  public function board(): BelongsTo
  {
    return $this->belongsTo(Board::class);
  }

  public function seats(): HasMany
  {
    return $this->hasMany(TableSeat::class);
  }

  // calls and cards of every board still attached to this table; a detached
  // (abandoned) playing no longer counts
  public function auctions(): HasManyThrough
  {
    return $this->hasManyThrough(Auction::class, BoardTable::class);
  }

  public function cardPlays(): HasManyThrough
  {
    return $this->hasManyThrough(Cardplay::class, BoardTable::class);
  }

  // boards this table has played
  public function boardPlays(): HasMany
  {
    return $this->hasMany(BoardTable::class);
  }

  // not tested yet
  public function players(): HasManyThrough
  {
    return $this->hasManyThrough(User::class, TableSeat::class, 'table_id', 'id', 'id', 'user_id');
  }
}

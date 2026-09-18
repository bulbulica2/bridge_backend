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

  protected $fillable = [
    'name',
    'created_by',
    'moderated_by',
    'board_id',
  ];

  /**
   * A table is active while somebody still sits at it. The last player to
   * leave deletes it, so an inactive table only exists mid-transaction.
   */
  public function scopeActive(Builder $query): void
  {
    $query->whereHas('seats');
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

  public function auctions(): HasMany
  {
    return $this->hasMany(Auction::class);
  }

  public function cardPlays(): HasMany
  {
    return $this->hasMany(Cardplay::class);
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

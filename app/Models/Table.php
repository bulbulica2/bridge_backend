<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Table extends Model
{
  use HasFactory;

  protected $fillable = [
    'name',
    'created_by',
    'moderated_by',
    'board_id',
    'closed_at',
  ];

  protected function casts(): array
  {
    return [
      'closed_at' => 'datetime',
    ];
  }

  public function scopeOpen(Builder $query): void
  {
    $query->whereNull('closed_at');
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

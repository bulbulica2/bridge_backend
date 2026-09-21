<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Board extends Model
{
  use HasFactory;

  protected $fillable = [
    'number',
    'dealer',
    'vulnerable',
  ];

  public function tables(): HasMany
  {
    return $this->hasMany(Table::class);
  }

  public function cards(): BelongsToMany
  {
    return $this->belongsToMany(Card::class)->withPivot('seat');
  }

  // calls made on this board, at every table, through each playing
  public function auctions(): HasManyThrough
  {
    return $this->hasManyThrough(Auction::class, BoardTable::class);
  }

  public function cardPlays(): HasManyThrough
  {
    return $this->hasManyThrough(Cardplay::class, BoardTable::class);
  }

  // every table that has played this board
  public function plays(): HasMany
  {
    return $this->hasMany(BoardTable::class);
  }
}

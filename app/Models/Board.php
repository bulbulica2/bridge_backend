<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Board extends Model
{
  use HasFactory;

  protected $fillable = [
    'vulnerable',
  ];

  public function tables(): HasMany
  {
    return $this->hasMany(Table::class);
  }

  public function cards(): BelongsToMany
  {
    return $this->belongsToMany(Card::class)->withPivot('position');
  }

  public function auctions(): HasMany
  {
    return $this->hasMany(Auction::class);
  }
}

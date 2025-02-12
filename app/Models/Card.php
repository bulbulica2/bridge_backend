<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Card extends Model
{
  protected $fillable = [
    'suit',
    'rank',
    'rank_name',
  ];

  public function boards(): BelongsToMany
  {
    return $this->belongsToMany(Board::class)->withPivot('seat');
  }

  public function cardPlays(): HasMany
  {
    return $this->hasMany(CardPlay::class);
  }
}

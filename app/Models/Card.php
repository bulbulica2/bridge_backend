<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Card extends Model
{
  protected $fillable = [
    'suit',
    'suit_name',
    'rank',
    'rank_name',
  ];

  public function board(): BelongsToMany
  {
    return $this->belongsToMany(Board::class);
  }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Board extends Model
{
  protected $fillable = [
    'board_id',
    'card_id',
  ];

  public function table(): BelongsToMany
  {
    return $this->belongsToMany(Table::class);
  }

  public function card(): BelongsToMany
  {
    return $this->belongsToMany(Card::class);
  }
}

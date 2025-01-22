<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Board extends Model
{
  use HasFactory;

  protected $fillable = [
    'position',
  ];

  public function table(): BelongsToMany
  {
    return $this->belongsToMany(Table::class);
  }

  public function cards(): BelongsToMany
  {
    return $this->belongsToMany(Card::class);
  }
}

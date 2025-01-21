<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Table extends Model
{
  protected $table = 'table';

  protected $fillable = [
    'name',
    'created_by',
    'current_board_id'
  ];

  public function user(): BelongsToMany
  {
    return $this->belongsToMany(User::class);
  }

  public function board(): BelongsToMany
  {
    return $this->belongsToMany(Board::class);
  }
}

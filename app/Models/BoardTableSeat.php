<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardTableSeat extends Model
{
  protected $fillable = [
    'board_table_id',
    'user_id',
    'seat',
    'ready_at',
  ];

  protected function casts(): array
  {
    return [
      'ready_at' => 'datetime',
    ];
  }

  public function boardTable(): BelongsTo
  {
    return $this->belongsTo(BoardTable::class);
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }
}

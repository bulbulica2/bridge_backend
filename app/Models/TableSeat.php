<?php

namespace App\Models;

use Database\Factories\TableSeatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableSeat extends Model
{
  /** @use HasFactory<TableSeatFactory> */
  use HasFactory;

  protected $fillable = [
    'table_id',
    'user_id',
    'seat',
  ];

  public function table(): BelongsTo
  {
    return $this->belongsTo(Table::class);
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }
}

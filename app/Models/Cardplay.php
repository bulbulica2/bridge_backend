<?php

namespace App\Models;

use Database\Factories\CardplayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cardplay extends Model
{
  /** @use HasFactory<CardplayFactory> */
  use HasFactory;

  protected $fillable = [
    'user_id',
    'table_id',
    'board_id',
    'card_id',
    'round',
    'order',
  ];

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function table(): BelongsTo
  {
    return $this->belongsTo(Table::class);
  }

  public function board(): BelongsTo
  {
    return $this->belongsTo(Board::class);
  }

  public function card(): BelongsTo
  {
    return $this->belongsTo(Card::class);
  }
}

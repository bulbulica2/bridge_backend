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
    'board_table_id',
    'card_id',
    'seat',
    'round',
    'order',
    'won_trick',
  ];

  protected function casts(): array
  {
    return [
      'won_trick' => 'boolean',
    ];
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  // the playing this card was played in; its board and table hang off it
  public function boardTable(): BelongsTo
  {
    return $this->belongsTo(BoardTable::class);
  }

  public function card(): BelongsTo
  {
    return $this->belongsTo(Card::class);
  }
}

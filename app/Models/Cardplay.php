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

  public function card(): BelongsTo
  {
    return $this->belongsTo(Card::class);
  }
}

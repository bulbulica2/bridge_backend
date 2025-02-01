<?php

namespace App\Models;

use Database\Factories\AuctionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Auction extends Model
{
  /** @use HasFactory<AuctionFactory> */
  use HasFactory;

  protected $fillable = [
    'board_id',
    'table_id',
    'user_id',
    'bid_id',
    'seat',
  ];

  public function board(): BelongsTo
  {
    return $this->belongsTo(Board::class);
  }

  public function table(): BelongsTo
  {
    return $this->belongsTo(Table::class);
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function bid(): BelongsTo
  {
    return $this->belongsTo(Bid::class);
  }
}

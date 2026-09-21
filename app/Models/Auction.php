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
    'board_table_id',
    'user_id',
    'bid_id',
    'seat',
  ];

  // the playing this call was made in; its board and table hang off it
  public function boardTable(): BelongsTo
  {
    return $this->belongsTo(BoardTable::class);
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

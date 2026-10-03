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

  public function bid(): BelongsTo
  {
    return $this->belongsTo(Bid::class);
  }
}

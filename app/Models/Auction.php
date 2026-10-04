<?php

namespace App\Models;

use Database\Factories\AuctionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call of a playing's auction, with its self-alert: `alerted` and the
 * bidder's `explanation`, for the opponents only until the board is over,
 * and `question_seat`, the opponent whose question about it is still open.
 */
class Auction extends Model
{
  /** @use HasFactory<AuctionFactory> */
  use HasFactory;

  /**
   * The longest `explanation`, in characters: `CallAlerted` carries it, and
   * must fit in 10 KB.
   */
  public const EXPLANATION_MAX = 200;

  protected $fillable = [
    'board_table_id',
    'user_id',
    'bid_id',
    'seat',
    'alerted',
    'explanation',
    'question_seat',
  ];

  protected function casts(): array
  {
    return [
      'alerted' => 'boolean',
    ];
  }

  public function bid(): BelongsTo
  {
    return $this->belongsTo(Bid::class);
  }
}

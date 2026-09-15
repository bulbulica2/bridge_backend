<?php

namespace App\Models;

use Database\Factories\BoardTableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One playing of a board at a table: the seat snapshot, auction result and outcome.
 */
class BoardTable extends Model
{
  /** @use HasFactory<BoardTableFactory> */
  use HasFactory;

  protected $table = 'board_table';

  protected $fillable = [
    'board_id',
    'table_id',
    'contract_bid_id',
    'doubled',
    'declarer_seat',
    'declarer_id',
    'tricks_won',
    'score',
    'started_at',
    'auction_ended_at',
    'finished_at',
  ];

  protected function casts(): array
  {
    return [
      'doubled' => 'integer',
      'tricks_won' => 'integer',
      'score' => 'integer',
      'started_at' => 'datetime',
      'auction_ended_at' => 'datetime',
      'finished_at' => 'datetime',
    ];
  }

  public function board(): BelongsTo
  {
    return $this->belongsTo(Board::class);
  }

  public function table(): BelongsTo
  {
    return $this->belongsTo(Table::class);
  }

  public function contractBid(): BelongsTo
  {
    return $this->belongsTo(Bid::class, 'contract_bid_id');
  }

  public function declarer(): BelongsTo
  {
    return $this->belongsTo(User::class, 'declarer_id');
  }

  public function seats(): HasMany
  {
    return $this->hasMany(BoardTableSeat::class);
  }

  // board_id + table_id is unique, so it identifies this playing's calls and cards
  public function auctions(): HasMany
  {
    return $this->hasMany(Auction::class, 'board_id', 'board_id')->where('table_id', $this->table_id);
  }

  public function cardPlays(): HasMany
  {
    return $this->hasMany(Cardplay::class, 'board_id', 'board_id')->where('table_id', $this->table_id);
  }
}

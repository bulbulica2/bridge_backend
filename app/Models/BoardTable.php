<?php

namespace App\Models;

use App\Services\CardPlayService;
use App\Services\ScoringService;
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

  public function auctions(): HasMany
  {
    return $this->hasMany(Auction::class);
  }

  public function cardPlays(): HasMany
  {
    return $this->hasMany(Cardplay::class);
  }

  /**
   * Close the playing: write its result and score and mark it finished.
   * The one place a board ends, whether the auction passed it out or the
   * 13th trick was played.
   *
   * `score` is stored from N-S's point of view, so it is turned round when
   * E-W declared. A passed out board scores 0 and has no `tricks_won`.
   *
   * @param  int|null  $tricksWon  tricks taken by declarer's side; null when passed out
   */
  public function finish(?int $tricksWon): void
  {
    if ($this->contractBid === null) {
      $this->update(['tricks_won' => null, 'score' => 0, 'finished_at' => now()]);

      return;
    }

    $score = ScoringService::score(
      $this->contractBid,
      (int) $this->doubled,
      $this->declarer_seat,
      $this->board->vulnerable,
      $tricksWon,
    );

    $this->update([
      'tricks_won' => $tricksWon,
      'score' => CardPlayService::side($this->declarer_seat) === 'ns' ? $score : -$score,
      'finished_at' => now(),
    ]);
  }

  /**
   * Delete this playing's call-by-call and card-by-card logs. Nothing
   * cascades them: they hang off `board_table`, which outlives its table.
   * The contract and result saved on this row are kept.
   */
  public function discardLogs(): void
  {
    $this->auctions()->delete();
    $this->cardPlays()->delete();
  }
}

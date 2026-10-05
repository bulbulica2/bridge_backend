<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Declarer's double dummy tricks after each card the opening leader could
 * lead, for one declarer and strain on a board. Written once, by
 * `SolveOpeningLeads`, when the first playing with that contract finishes.
 */
class BoardLeadAnalysis extends Model
{
  protected $fillable = [
    'board_id',
    'declarer_seat',
    'strain',
    'leads',
  ];

  protected function casts(): array
  {
    return [
      'leads' => 'array',
    ];
  }
}

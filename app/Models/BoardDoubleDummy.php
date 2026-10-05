<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A board's double dummy table: declarer's tricks for each declarer and
 * strain with best play all round. Written once, by `SolveDoubleDummyTable`.
 */
class BoardDoubleDummy extends Model
{
  protected $table = 'board_double_dummy';

  protected $fillable = [
    'board_id',
    'tricks',
  ];

  protected function casts(): array
  {
    return [
      'tricks' => 'array',
    ];
  }
}

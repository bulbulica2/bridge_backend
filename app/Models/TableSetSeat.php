<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableSetSeat extends Model
{
  protected $fillable = [
    'table_set_id',
    'user_id',
    'seat',
  ];

  public function tableSet(): BelongsTo
  {
    return $this->belongsTo(TableSet::class);
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }
}

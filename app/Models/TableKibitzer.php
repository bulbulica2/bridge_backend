<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody watching a table without a seat (`KibitzerService`).
 */
class TableKibitzer extends Model
{
  protected $fillable = [
    'table_id',
    'user_id',
    'last_seen_at',
  ];

  protected function casts(): array
  {
    return [
      'last_seen_at' => 'datetime',
    ];
  }

  protected static function booted(): void
  {
    // starting to watch is a sign of life
    static::creating(function (TableKibitzer $kibitzer) {
      $kibitzer->last_seen_at ??= now();
    });
  }

  public function table(): BelongsTo
  {
    return $this->belongsTo(Table::class);
  }
}

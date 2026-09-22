<?php

namespace App\Models;

use App\auxiliary\Suits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One of the 38 calls a player can make: the three special ones (`P`, `X`,
 * `XX`) and the 35 contract bids `1C`…`7NT`.
 *
 * A contract bid's rank is its `level` and `strain`, never its row id. The
 * seeder happens to insert them in ascending order, but nothing enforces
 * that, so ordering by id would silently invert if the seeder changed.
 */
class Bid extends Model
{
  public const PASS = 'P';

  public const DOUBLE = 'X';

  public const REDOUBLE = 'XX';

  protected $fillable = [
    'suit',
    'suit_name',
    'special',
    'level',
    'strain',
  ];

  protected function casts(): array
  {
    return [
      'special' => 'boolean',
      'level' => 'integer',
    ];
  }

  /**
   * The 35 contract bids, i.e. everything but `P`, `X` and `XX`.
   */
  public function scopeContracts(Builder $query): void
  {
    $query->where('special', false);
  }

  /**
   * `P`, `X` and `XX`.
   */
  public function scopeSpecials(Builder $query): void
  {
    $query->where('special', true);
  }

  /**
   * Does this call outrank `$other`? Higher level wins; at the same level the
   * strain decides, clubs lowest through no trump highest.
   *
   * Only contract bids have a rank. Pass, double and redouble are legal on
   * their own terms rather than by outranking anything, so comparing them is
   * a mistake worth failing loudly on.
   */
  public function isHigherThan(self $other): bool
  {
    return $this->rank() > $other->rank();
  }

  /**
   * Sort key for a contract bid: level first, then strain.
   *
   * @return array{int, int}
   */
  public function rank(): array
  {
    if ($this->special || $this->level === null || $this->strain === null) {
      throw new LogicException("Call '{$this->suit}' has no rank; only contract bids can be compared.");
    }

    return [$this->level, Suits::strainRank($this->strain)];
  }

  public function isPass(): bool
  {
    return $this->suit === self::PASS;
  }

  public function isDouble(): bool
  {
    return $this->suit === self::DOUBLE;
  }

  public function isRedouble(): bool
  {
    return $this->suit === self::REDOUBLE;
  }

  /**
   * A contract bid (`1C`…`7NT`), as opposed to `P`, `X` or `XX`.
   */
  public function isContract(): bool
  {
    return ! $this->special;
  }

  public function auctions(): HasMany
  {
    return $this->hasMany(Auction::class);
  }
}

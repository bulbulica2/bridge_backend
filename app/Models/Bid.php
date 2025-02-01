<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bid extends Model
{
  protected $fillable = [
    'suit',
    'suit_name',
  ];

  public function auctions(): HasMany
  {
    return $this->hasMany(Auction::class);
  }
}

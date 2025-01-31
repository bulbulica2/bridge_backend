<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Table extends Model
{
  use HasFactory;

  protected $fillable = [
    'name',
    'created_by',
    'moderated_by',
    'north_id',
    'south_id',
    'west_id',
    'east_id',
    'board_id',
  ];

  public function createdBy(): HasOne
  {
    return $this->hasOne(User::class, 'id', 'created_by');
  }

  public function moderatedBy(): HasOne
  {
    return $this->hasOne(User::class, 'id', 'moderated_by');
  }

  public function north(): HasOne
  {
    return $this->hasOne(User::class, 'id', 'north_id');
  }

  public function east(): HasOne
  {
    return $this->hasOne(User::class, 'id', 'east_id');
  }

  public function south(): HasOne
  {
    return $this->hasOne(User::class, 'id', 'south_id');
  }

  public function west(): HasOne
  {
    return $this->hasOne(User::class, 'id', 'west_id');
  }

  public function board(): HasOne
  {
    return $this->hasOne(Board::class, 'id', 'board_id');
  }
}

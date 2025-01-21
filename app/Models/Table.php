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
    return $this->hasOne(User::class, 'id', 'north');
  }

  public function east(): HasOne
  {
    return $this->hasOne(User::class, 'id', 'east');
  }

  public function south(): HasOne
  {
    return $this->hasOne(User::class, 'id', 'south');
  }

  public function west(): HasOne
  {
    return $this->hasOne(User::class, 'id', 'west');
  }
}

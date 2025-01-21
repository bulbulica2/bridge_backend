<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
  /** @use HasFactory<UserFactory> */
  use HasFactory, Notifiable;

  /**
   * The attributes that are mass assignable.
   *
   * @var list<string>
   */
  protected $fillable = [
    'name',
    'username',
    'email',
    'password',
    'description',
  ];

  /**
   * The attributes that should be hidden for serialization.
   *
   * @var list<string>
   */
  protected $hidden = [
    'password',
    'remember_token',
    'is_admin',
  ];

  /**
   * Get the attributes that should be cast.
   *
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'email_verified_at' => 'datetime',
      'password' => 'hashed',
    ];
  }

  public function createdTable(): BelongsTo
  {
    return $this->belongsTo(Table::class, 'id', 'created_by');
  }

  public function moderatedTable(): BelongsTo
  {
    return $this->belongsTo(Table::class, 'id', 'moderated_by');
  }

  /**
   * @throws Exception
   */
  public function seatPosition()
  {
    if (!$this->id) {
      throw new \Exception("User ID is not set in the instance.");
    }

    $rows = DB::table('tables')
      ->where('north_id', $this->id)
      ->orWhere('east_id', $this->id)
      ->orWhere('south_id', $this->id)
      ->orWhere('west_id', $this->id)
      ->get();

    return $rows->map(function ($row) {
      $seat = null;

      if ($this->id == $row->north_id) {
        $seat = 'north_id';
      } elseif ($this->id == $row->east_id) {
        $seat = 'east_id';
      } elseif ($this->id == $row->south_id) {
        $seat = 'south_id';
      } elseif ($this->id == $row->west_id) {
        $seat = 'west_id';
      }

      return (object) array_merge((array) $row, ['user_seat' => $seat]);
    });
  }
}

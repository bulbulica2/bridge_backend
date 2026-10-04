<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
  /** @use HasFactory<UserFactory> */
  use HasFactory, Notifiable;

  /**
   * The longest `name` and `username`, in characters. Every table broadcast
   * carries four players' names, and a broadcast must fit in 10 KB even when
   * each character is an emoji JSON-escaped twice (14 bytes).
   */
  public const NAME_MAX = 50;

  public const USERNAME_MAX = 30;

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
      'is_admin' => 'boolean',
      'is_robot' => 'boolean',
    ];
  }

  /**
   * Robot players. `is_robot` is never mass assignable, so only
   * `RobotService` makes them.
   */
  public function scopeRobots(Builder $query): void
  {
    $query->where('is_robot', true);
  }

  public function scopeHumans(Builder $query): void
  {
    $query->where('is_robot', false);
  }

  /**
   * The user's record as shown to themselves (GET and PATCH /api/user):
   * email included, plus `is_admin` (hidden from the model's JSON; others see
   * it through `UserResource`), and
   * `ban`, the ban keeping them away from the game (null when there is none).
   *
   * @return array<string, mixed>
   */
  public function toOwnArray(): array
  {
    return [
      ...$this->toArray(),
      'is_admin' => (bool) $this->is_admin,
      'ban' => $this->activeBan()?->toOwnArray(),
    ];
  }

  /**
   * Every ban this user has had, current, lifted or run out, latest first.
   */
  public function bans(): HasMany
  {
    return $this->hasMany(UserBan::class)->latest('banned_at')->latest('id');
  }

  /**
   * The ban in force now, if any. Read fresh every time: it runs out by
   * itself at `until`.
   */
  public function activeBan(): ?UserBan
  {
    return $this->bans()->active()->first();
  }

  public function createdTables(): HasMany
  {
    return $this->hasMany(Table::class, 'created_by');
  }

  public function seats(): HasMany
  {
    return $this->hasMany(TableSeat::class);
  }

  // which boards the user played, and from which seat
  public function playedSeats(): HasMany
  {
    return $this->hasMany(BoardTableSeat::class);
  }
}

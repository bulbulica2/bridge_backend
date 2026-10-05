<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The robots players are seated from: `users` rows with `is_robot`
 * (`robot-<n>`), made the first time the pool runs dry and reused after.
 * `unique(user_id)` still holds on `table_seats`, so every robot sits at
 * one table at a time. Robots can't log in.
 *
 * Used by `RobotService::seatRobot()` (a manager seats a robot) and by
 * `TableSeatService::remove()` (a robot takes the seat of a player who
 * walked out on a set).
 */
class RobotPool
{
  /**
   * How many robots a caller tries before giving up, when another request
   * seats the one it picked at the same moment.
   */
  public const ATTEMPTS = 3;

  /**
   * A robot that sits nowhere, made if every robot is busy.
   *
   * @param  list<int>  $except  robots already tried
   */
  public function idle(array $except = []): User
  {
    return User::robots()
      ->whereDoesntHave('seats')
      ->whereNotIn('id', $except)
      ->orderBy('id')
      ->first()
      ?? $this->make();
  }

  /**
   * A new `robot-<n>`: next number up, and another if a concurrent request
   * took it. Its password is random and never stored anywhere else, and
   * login refuses robots anyway.
   */
  private function make(): User
  {
    $number = User::robots()->count() + 1;

    for ($attempt = 1; ; $attempt++, $number++) {
      try {
        return DB::transaction(function () use ($number) {
          $robot = new User([
            'name' => "Robot $number",
            'username' => "robot-$number",
            'email' => "robot-$number@robots.invalid",
            'password' => Hash::make(Str::random(40)),
            'description' => 'A robot player.',
          ]);
          $robot->is_robot = true;
          $robot->email_verified_at = now();
          $robot->save();

          return $robot;
        });
      } catch (QueryException $e) {
        if ((string) $e->getCode() !== '23000' || $attempt >= self::ATTEMPTS * 3) {
          throw $e;
        }
      }
    }
  }
}

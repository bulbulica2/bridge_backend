<?php

namespace Database\Factories;

use App\auxiliary\Seats;
use App\auxiliary\Vulnerability;
use App\Models\Board;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Board>
 */
class BoardFactory extends Factory
{
  // sequential board numbers across the whole seeding run
  protected static int $boardNumber = 0;

  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition(): array
  {
    return [
      'number' => ++static::$boardNumber,
      // derived from number, so a state that overrides number stays consistent
      'dealer' => fn(array $attributes) => Seats::dealerForBoard($attributes['number']),
      'vulnerable' => fn(array $attributes) => Vulnerability::forBoard($attributes['number']),
    ];
  }
}

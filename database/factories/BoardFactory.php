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
  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition(): array
  {
    return [
      // carry on from the highest board stored, so factory-made boards and
      // the ones BoardSelectionService deals share one sequence
      'number' => fn () => (int) (Board::max('number') ?? 0) + 1,
      // derived from number, so a state that overrides number stays consistent
      'dealer' => fn (array $attributes) => Seats::dealerForBoard($attributes['number']),
      'vulnerable' => fn (array $attributes) => Vulnerability::forBoard($attributes['number']),
    ];
  }
}

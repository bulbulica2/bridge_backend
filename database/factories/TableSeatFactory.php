<?php

namespace Database\Factories;

use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TableSeat>
 */
class TableSeatFactory extends Factory
{
  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition(): array
  {
    return [
      'table_id' => Table::factory(),
      'user_id' => User::factory(),
      'seat' => $this->faker->randomElement(['N', 'S', 'E', 'W']),
    ];
  }
}

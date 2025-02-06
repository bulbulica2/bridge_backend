<?php

namespace Database\Factories;

use App\Models\Board;
use App\Models\Card;
use App\Models\Table;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Cardplay>
 */
class CardplayFactory extends Factory
{
  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition(): array
  {
    return [
      'user_id' => User::factory(),
      'table_id' => Table::factory(),
      'board_id' => Board::factory(),
      'card_id' => Card::factory(),
      'round' => $this->faker->numberBetween(1,13),
      'order' => $this->faker->numberBetween(1,4),
    ];
  }
}

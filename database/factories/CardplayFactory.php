<?php

namespace Database\Factories;

use App\auxiliary\Seats;
use App\Models\BoardTable;
use App\Models\Card;
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
      'board_table_id' => BoardTable::factory(),
      // Card has no factory; cards are static reference data
      'card_id' => fn() => Card::inRandomOrder()->value('id'),
      'seat' => $this->faker->randomElement(Seats::SEATS),
      'round' => $this->faker->numberBetween(1,13),
      'order' => $this->faker->numberBetween(1,4),
    ];
  }
}

<?php

namespace Database\Factories;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\Board;
use App\Models\Table;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Auction>
 */
class AuctionFactory extends Factory
{
  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition(): array
  {
    return [
      'board_id' => Board::inRandomOrder()->first()->id ?? Board::factory(),
      'table_id' => Table::inRandomOrder()->first()->id ?? Table::factory(),
      'user_id' => User::inRandomOrder()->first()->id ?? User::factory(),
      'bid_id' => Bid::inRandomOrder()->first()->id ?? null,
      'seat' => $this->faker->randomElement(['north', 'south', 'west', 'east']),
      'created_at' => now(),
      'updated_at' => now(),
    ];
  }
}

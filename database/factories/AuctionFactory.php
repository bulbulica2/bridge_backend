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
      'board_id' => Board::factory(),
      'table_id' => Table::factory(),
      'user_id' => User::factory(),
      'bid_id' => fn () => Bid::inRandomOrder()->value('id'),
      'seat' => $this->faker->randomElement(['N', 'S', 'W', 'E']),
    ];
  }
}

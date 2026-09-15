<?php

namespace Database\Factories;

use App\auxiliary\Seats;
use App\Models\Bid;
use App\Models\Board;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardTable>
 */
class BoardTableFactory extends Factory
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
      'started_at' => now(),
    ];
  }

  // needs bids seeded (BidSeeder)
  public function auctionEnded(): static
  {
    return $this->state(fn(array $attributes) => [
      'contract_bid_id' => fn() => Bid::where('special', false)->inRandomOrder()->value('id'),
      'doubled' => $this->faker->numberBetween(0, 2),
      'declarer_seat' => $this->faker->randomElement(Seats::SEATS),
      'declarer_id' => User::factory(),
      'auction_ended_at' => now(),
    ]);
  }

  public function finished(): static
  {
    return $this->auctionEnded()->state(fn(array $attributes) => [
      'tricks_won' => $this->faker->numberBetween(0, 13),
      'finished_at' => now(),
    ]);
  }
}

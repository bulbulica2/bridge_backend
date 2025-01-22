<?php

namespace Database\Factories;

use App\Models\Board;
use App\Models\Table;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Table>
 */
class TableFactory extends Factory
{
  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition(): array
  {
    $owner = User::factory()->create();
    $guest1 = User::factory()->create();
    $guest2 = User::factory()->create();
    $guest3 = User::factory()->create();
    $board = Board::factory()->create();

    return [
      'name' => $this->faker->name(),
      'created_by' => $owner->id,
      'moderated_by' => $owner->id,
      'current_board_id' => $board->id,
      'north' => $owner->id,
      'east' => rand(0,1) ? $guest1->id : null,
      'west' => rand(0,1) ? $guest2->id : null,
      'south' => rand(0,1) ? $guest3->id : null,
    ];
  }
}

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
    return [
      'created_by' => User::factory(),
      'moderated_by' => User::factory(),
      'board_id' => Board::factory(),
    ];
  }
}

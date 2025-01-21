<?php

namespace Database\Factories;

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

    return [
      'name' => $this->faker->name(),
      'created_by' => $owner->id,
      'moderated_by' => $owner->id,
      'north' => $owner->id,
      'east' => $guest1->id,
      'west' => $guest2->id,
      'south' => $guest3->id,
    ];
  }
}

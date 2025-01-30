<?php

namespace Database\Factories;

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
    $randomVulnerability = rand(0,3);
    if ($randomVulnerability === 0) {
      $vulnerable = '-';
    } else if ($randomVulnerability === 1) {
      $vulnerable = 'N-S';
    } else if ($randomVulnerability === 2) {
      $vulnerable = 'E-W';
    } else {
      $vulnerable = 'N-S, E-W';
    }

    return [
      'vulnerable' => $vulnerable,
    ];
  }
}

<?php

namespace Database\Seeders;

use App\Models\Board;
use App\Models\User;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\BoardSeeder;
use Database\Seeders\game\CardSeeder;
use Database\Seeders\game\TableSeeder;
use Database\Seeders\game\UserSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
  /**
   * Seed the application's database.
   */
  public function run(): void
  {
    if (env('APP_ENV') === 'local') {
      $this->runLocalEnvironmentSeeder();
    } else if (env('APP_ENV') === 'production') {
      $this->runProductionEnvironmentSeeder();
    } else {
      $this->runLocalEnvironmentSeeder();
    }
  }

  protected function runLocalEnvironmentSeeder(): void
  {
    $this->defaultEnvironmentSeed();

    $this->call([
      BoardSeeder::class,
      UserSeeder::class,
      TableSeeder::class,
      TableSeatSeeder::class,
      AuctionSeeder::class,
      CardplaySeeder::class,
    ]);

    User::factory(2)->unverified()->create();
    User::factory()->isAdmin()->create();
  }

  private function runProductionEnvironmentSeeder(): void
  {
    $this->defaultEnvironmentSeed();

    Board::factory(100)->create();
  }

  private function defaultEnvironmentSeed(): void
  {
    $this->call([
      CardSeeder::class,
      BidSeeder::class,
    ]);
  }
}

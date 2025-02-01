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
    $this->call([
      CardSeeder::class,
      BoardSeeder::class,
      UserSeeder::class,
      TableSeeder::class,
      BidSeeder::class,
      AuctionSeeder::class,
    ]);

    User::factory(2)->unverified()->create();
    User::factory()->isAdmin()->create();
//    Table::factory(5)->create();
  }

  private function runProductionEnvironmentSeeder(): void
  {
    $this->call([
      CardSeeder::class,
      BidSeeder::class,
    ]);

    Board::factory(100)->create();
  }
}

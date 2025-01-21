<?php

namespace Database\Seeders;

use App\Models\Table;
use App\Models\User;
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
    ]);

    User::factory(2)->unverified()->create();
    User::factory()->isAdmin()->create();
    Table::factory(5)->create();
  }

  private function runProductionEnvironmentSeeder(): void
  {
    $this->call([
      CardSeeder::class,
      BoardSeeder::class,
    ]);
  }
}

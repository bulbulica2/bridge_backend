<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

class TestEnvironmentTest extends TestCase
{
  public function test_suite_runs_on_in_memory_sqlite(): void
  {
    $this->assertSame('testing', app()->environment());
    $this->assertSame('sqlite', DB::connection()->getDriverName());
    $this->assertSame(':memory:', DB::connection()->getDatabaseName());
  }

  public function test_dev_config_cache_is_never_loaded(): void
  {
    $this->assertNotSame(base_path('bootstrap/cache/config.php'), app()->getCachedConfigPath());
  }

  public function test_guard_accepts_the_phpunit_config(): void
  {
    $this->assertIsolatedEnvironment(config()->all());
    $this->addToAssertionCount(1);
  }

  public function test_guard_fails_on_mysql(): void
  {
    $config = config()->all();
    $config['database']['default'] = 'mysql';

    $this->assertGuardFails($config, 'on mysql (mysql,');
  }

  public function test_guard_fails_on_a_sqlite_file(): void
  {
    $config = config()->all();
    $config['database']['connections']['sqlite']['database'] = database_path('database.sqlite');

    $this->assertGuardFails($config, 'on sqlite (sqlite, database');
  }

  public function test_guard_fails_outside_the_testing_env(): void
  {
    $config = config()->all();
    $config['app']['env'] = 'local';

    $this->assertGuardFails($config, 'got APP_ENV=local');
  }

  private function assertGuardFails(array $config, string $detail): void
  {
    try {
      $this->assertIsolatedEnvironment($config);
    } catch (AssertionFailedError $e) {
      $this->assertStringContainsString($detail, $e->getMessage());
      $this->assertStringContainsString('php artisan config:clear', $e->getMessage());

      return;
    }

    $this->fail('The guard accepted a non-isolated config.');
  }
}

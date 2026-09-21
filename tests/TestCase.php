<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
  public function createApplication()
  {
    $app = parent::createApplication();

    // runs before setUpTraits(), so RefreshDatabase never gets to migrate a
    // real database when the phpunit.xml env was ignored
    $this->assertIsolatedEnvironment($app['config']->all());

    return $app;
  }

  /**
   * Fail unless the config is the one phpunit.xml asks for: APP_ENV=testing
   * on in-memory sqlite. A stale config cache or a DB_* variable exported in
   * the shell both win over phpunit.xml, and would otherwise point
   * RefreshDatabase at the MySQL dev DB.
   */
  protected function assertIsolatedEnvironment(array $config): void
  {
    $default = $config['database']['default'] ?? null;
    $connection = $config['database']['connections'][$default] ?? [];
    $env = $config['app']['env'] ?? null;

    if (($connection['driver'] ?? null) === 'sqlite'
      && ($connection['database'] ?? null) === ':memory:'
      && $env === 'testing') {
      return;
    }

    $this->fail(sprintf(
      'Tests must run with APP_ENV=testing on in-memory sqlite, got APP_ENV=%s on %s (%s, database %s). '
        .'A cached config or a DB_*/APP_ENV variable set in the shell is overriding phpunit.xml. '
        .'Run: php artisan config:clear, and unset any such shell variable.',
      $env ?? 'null',
      $default ?? 'null',
      $connection['driver'] ?? 'unknown driver',
      $connection['database'] ?? 'null',
    ));
  }
}

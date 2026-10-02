<?php

namespace Tests;

use App\Models\BoardTable;
use App\Models\Table;
use App\Services\BoardSelectionService;
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
   * Every human seated at the table presses Start, through the service, as
   * `POST /tables/{table}/start` does; robots are ready already. With four
   * seated that deals the board, which this returns. Mutates `$table`
   * (`board_id`).
   */
  protected function startBoard(Table $table): ?BoardTable
  {
    $playing = null;

    foreach ($table->seats()->with('user')->get()->reject(fn ($seat) => $seat->user->is_robot) as $seat) {
      $playing = app(BoardSelectionService::class)->start($table, $seat->user) ?? $playing;
    }

    return $playing;
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

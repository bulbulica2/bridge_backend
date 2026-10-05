<?php

namespace App\Console\Commands;

use App\Services\DoubleDummyService;
use Illuminate\Console\Command;

class SolveMissingDoubleDummy extends Command
{
  protected $signature = 'dds:solve-missing';

  protected $description = 'Queue the double dummy analysis of every board and finished contract that has none yet';

  public function handle(DoubleDummyService $doubleDummy): int
  {
    if (! $doubleDummy->available()) {
      $this->error('There is no double dummy solver: DDS_LIBRARY is not set. `php artisan dds:check` says what is missing.');

      return self::FAILURE;
    }

    ['tables' => $tables, 'leads' => $leads] = $doubleDummy->queueMissing();

    $this->info('Queued '.$tables.' '.($tables === 1 ? 'table' : 'tables').' and the opening leads of '.$leads.' '.($leads === 1 ? 'contract' : 'contracts').'.');
    $this->line('queue:work solves them; `php artisan queue:failed` lists any that fail.');

    return self::SUCCESS;
  }
}

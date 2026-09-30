<?php

namespace App\Console\Commands;

use App\Services\TableSeatService;
use Illuminate\Console\Command;

class DeleteUnattendedTables extends Command
{
  protected $signature = 'tables:delete-unattended';

  protected $description = 'Delete tables only robots have kept since their last human left';

  public function handle(TableSeatService $seats): int
  {
    $deleted = $seats->deleteUnattendedTables();

    $this->info("Deleted $deleted unattended ".($deleted === 1 ? 'table' : 'tables').'.');

    return self::SUCCESS;
  }
}

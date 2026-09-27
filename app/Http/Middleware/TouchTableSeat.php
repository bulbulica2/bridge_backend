<?php

namespace App\Http\Middleware;

use App\Models\Table;
use App\Services\TableSeatService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts a request on a table's playing endpoints as a sign of life from the
 * caller, so a player who is busy bidding or playing is never released as
 * idle. Does nothing for a caller who doesn't sit at the table; the
 * controller refuses them as usual.
 */
class TouchTableSeat
{
  public function __construct(private TableSeatService $seats) {}

  public function handle(Request $request, Closure $next): Response
  {
    $table = $request->route('table');

    if ($table instanceof Table && $request->user() !== null) {
      $this->seats->touch($table, $request->user());
    }

    return $next($request);
  }
}

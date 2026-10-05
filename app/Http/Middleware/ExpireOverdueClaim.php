<?php

namespace App\Http\Middleware;

use App\Models\Table;
use App\Services\ClaimService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expires the table's claim before the request reads or acts on its
 * playing, once the claim's time to answer is up
 * (`ClaimService::expireOverdue()`). `ExpireClaim` does it on time, but
 * only while a queue worker runs; without one, this is what keeps the table
 * from waiting on a claim nobody may answer any more.
 */
class ExpireOverdueClaim
{
  public function __construct(private ClaimService $claims) {}

  public function handle(Request $request, Closure $next): Response
  {
    $table = $request->route('table');

    if ($table instanceof Table) {
      $this->claims->expireOverdue($table);
    }

    return $next($request);
  }
}

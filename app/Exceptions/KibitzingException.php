<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Somebody can't start or stop watching a table: it doesn't allow
 * kibitzers (403), they are seated somewhere (409), or they weren't
 * watching it (409). The message says which, `status` the HTTP status a
 * controller answers with.
 */
class KibitzingException extends RuntimeException
{
  public function __construct(string $message, public readonly int $status)
  {
    parent::__construct($message);
  }
}

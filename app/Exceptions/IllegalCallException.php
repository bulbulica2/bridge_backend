<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A call can't be made: it isn't the caller's turn, the call breaks the
 * bidding rules, or the table isn't in its auction. The message says which.
 * Controllers map it to a 409.
 */
class IllegalCallException extends RuntimeException {}

<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A player can't press (or take back) Start: they aren't seated at the
 * table, or a board is already in progress there — in its auction or play,
 * or finished with the same four still seated, who go on with Next. The
 * message says which. Controllers map it to a 409.
 */
class StartBoardException extends RuntimeException {}

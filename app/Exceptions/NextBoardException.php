<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A table can't be asked for its next board: it has no board yet, the one it
 * is on isn't finished, or it is short of a player. The message says which.
 * Controllers map it to a 409.
 */
class NextBoardException extends RuntimeException {}

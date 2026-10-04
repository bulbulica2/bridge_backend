<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A chat message can't be sent: the table has no board, or it is for the
 * whole table while the board is being bid or played. The message says
 * which. Controllers map it to a 409.
 */
class IllegalMessageException extends RuntimeException {}

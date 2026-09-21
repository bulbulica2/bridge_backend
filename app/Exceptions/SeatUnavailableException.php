<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A seat change can't go through: the seat is invalid or taken, the user
 * already sits somewhere, or — when leaving — they hold no seat at that table.
 * Controllers map it to a 409.
 */
class SeatUnavailableException extends RuntimeException {}

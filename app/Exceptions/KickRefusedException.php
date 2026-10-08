<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A kick the caller may make in general (`TablePolicy::kick`) but not now:
 * a moderator taking a player out in the middle of a set
 * (`TableSeatService::kick()`). Controllers map it to a 409.
 */
class KickRefusedException extends RuntimeException {}

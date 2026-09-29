<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A claim can't be made, answered or withdrawn: the table isn't in its play,
 * the caller is dummy, the tricks are out of range, a claim is already
 * pending (or none is), or the caller isn't the one to answer or withdraw it.
 * The message says which. Controllers map it to a 409.
 */
class IllegalClaimException extends RuntimeException {}

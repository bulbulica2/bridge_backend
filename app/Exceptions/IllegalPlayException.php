<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A card can't be played: it isn't the player's turn, the card isn't in the
 * hand being played from, it doesn't follow suit, or the table isn't in its
 * play. The message says which. Controllers map it to a 409.
 */
class IllegalPlayException extends RuntimeException {}

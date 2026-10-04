<?php

namespace App\Models;

use App\auxiliary\Seats;
use Illuminate\Database\Eloquent\Model;

/**
 * One message of a board's chat (`App\Services\BoardChatService`): sent by
 * `user_id` from `seat`, `to` the opponents or the whole table, about the
 * call at `call_index` of the auction when that is set.
 */
class BoardMessage extends Model
{
  /** The sender and their two opponents: never partner. */
  public const TO_OPPONENTS = 'opponents';

  /** All four, only while no board is being bid or played. */
  public const TO_TABLE = 'table';

  public const TO = [self::TO_OPPONENTS, self::TO_TABLE];

  /**
   * The longest `body`, in characters: `BoardMessageSent` carries it, and
   * must fit in 10 KB.
   */
  public const BODY_MAX = 500;

  protected $fillable = [
    'board_table_id',
    'user_id',
    'seat',
    'to',
    'call_index',
    'body',
  ];

  protected function casts(): array
  {
    return [
      'call_index' => 'integer',
    ];
  }

  /**
   * Whether the player in `$seat` may read this message: everyone once the
   * board is finished, everyone a `table` message, and otherwise everyone
   * but the sender's partner, who would get unauthorised information.
   */
  public function visibleTo(?string $seat, bool $finished): bool
  {
    return $finished
      || $this->to === self::TO_TABLE
      || ($seat !== null && $seat !== Seats::partner($this->seat));
  }
}

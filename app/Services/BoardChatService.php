<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\Events\BoardMessageSent;
use App\Exceptions\IllegalMessageException;
use App\Models\BoardMessage;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use App\Robots\RobotBidder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The board's chat (`GAME-RULES.md` §4, table talk): messages between the
 * players seated at a table, kept against the board in play, or the last
 * one finished.
 *
 * While the board is bid or played a player talks to the opponents only
 * (`opponents`: the sender and both opponents read it, never partner); a
 * message to all four (`table`) waits until the board is finished. Once it
 * is, every message of the board is public, to the table and in the review.
 *
 * A message about a call of the other side (`call_index`) is a question:
 * a robot bidder answers it at once with what its system reads into the
 * call. Robots don't otherwise chat.
 */
class BoardChatService
{
  public function __construct(private PlayingStateService $state) {}

  /**
   * The current board's playing (null while the table has none) and the
   * messages of it `$user` may read, oldest first.
   *
   * @return array{BoardTable|null, Collection<int, BoardMessage>}
   */
  public function messagesFor(Table $table, User $user): array
  {
    $playing = $this->state->currentPlaying($table);

    if ($playing === null) {
      return [null, collect()];
    }

    $seat = self::seatAt($table, $user);
    $finished = $playing->finished_at !== null;

    $messages = $playing->messages()
      ->orderBy('id')
      ->get()
      ->filter(fn (BoardMessage $message) => $message->visibleTo($seat, $finished))
      ->values();

    return [$playing, $messages];
  }

  /**
   * Send `$user`'s message to the current board's chat, `$to` the
   * opponents or the table, about the call at `$callIndex` (from 0) when
   * that is set. The playing's row is locked, as for a call or a card, so
   * the phase can't change under the message.
   *
   * @throws IllegalMessageException
   */
  public function send(Table $table, User $user, string $body, string $to, ?int $callIndex = null): BoardMessage
  {
    return DB::transaction(function () use ($table, $user, $body, $to, $callIndex) {
      $playing = $this->state->currentPlaying($table, lock: true);

      if ($playing === null) {
        throw new IllegalMessageException('The table has no board yet: the chat opens with the first deal.');
      }

      if ($to === BoardMessage::TO_TABLE && $this->state->phase($playing) !== PlayingStateService::PHASE_FINISHED) {
        throw new IllegalMessageException("Partners can't talk while the board is bid or played: send it to the opponents.");
      }

      $seat = self::seatAt($table, $user);

      if ($seat === null) {
        throw new IllegalMessageException('You are not seated at this table.');
      }

      $call = null;

      if ($callIndex !== null) {
        $call = $playing->auctions->sortBy('id')->values()->get($callIndex);

        if ($call === null) {
          throw new IllegalMessageException("There is no call $callIndex in the auction.");
        }
      }

      $message = $this->post($playing, $user, $seat, $to, $body, $callIndex);

      // a question about the other side's call: a robot bidder answers it
      if ($call !== null && $call->seat !== $seat && $call->seat !== Seats::partner($seat)) {
        $bidder = $playing->seats->firstWhere('seat', $call->seat)->user;

        if ($bidder->is_robot) {
          $this->post($playing, $bidder, $call->seat, $to, $this->robotReading($playing, $callIndex), $callIndex);
        }
      }

      return $message;
    });
  }

  /**
   * Write a message into `$playing`'s chat and send it (`BoardMessageSent`)
   * to every human seated at its table who may read it, the sender
   * included. No rule is checked here: `send()` checks a player's
   * message, and `AuctionService` writes its questions and answers about
   * calls through this too.
   */
  public function post(BoardTable $playing, User $user, string $seat, string $to, string $body, ?int $callIndex = null): BoardMessage
  {
    $message = $playing->messages()->create([
      'user_id' => $user->id,
      'seat' => $seat,
      'to' => $to,
      'call_index' => $callIndex,
      'body' => mb_substr($body, 0, BoardMessage::BODY_MAX),
    ]);

    $finished = $playing->finished_at !== null;
    $readers = TableSeat::query()->where('table_id', $playing->table_id)->with('user')->get();

    foreach ($readers as $reader) {
      if (! $reader->user->is_robot && $message->visibleTo($reader->seat, $finished)) {
        BoardMessageSent::dispatch((int) $reader->user_id, (int) $playing->table_id, (int) $playing->id, $message);
      }
    }

    return $message;
  }

  /**
   * What the robots' bidding system reads into the call at `$index`
   * (`RobotBidder::read()`): a robot's answer to a question about it.
   */
  public function robotReading(BoardTable $playing, int $index): string
  {
    $calls = array_map(
      fn ($made) => ['seat' => $made['seat'], 'call' => $made['bid']->suit],
      $this->state->calls($playing),
    );

    return RobotBidder::read($calls)[$index]->explanation();
  }

  /**
   * The seat `$user` holds at `$table` now, or null.
   */
  private static function seatAt(Table $table, User $user): ?string
  {
    return TableSeat::query()->where('table_id', $table->getKey())->where('user_id', $user->getKey())->value('seat');
  }
}

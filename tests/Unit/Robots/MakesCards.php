<?php

namespace Tests\Unit\Robots;

use App\auxiliary\Seats;
use App\Models\Bid;
use App\Models\Card;
use App\Robots\RobotHand;

/**
 * Cards, hands and calls in memory, no database, in the payload shapes the
 * robots read.
 */
trait MakesCards
{
  private const RANK_NAMES = ['J' => 12, 'Q' => 13, 'K' => 14, 'A' => 15, 'T' => 10];

  /**
   * A card from its code (`SA`, `H10`, `DT`), with an id unique to suit
   * and rank.
   *
   * @return array{id: int, suit: string, rank: int}
   */
  private function card(string $code): array
  {
    $suit = $code[0];
    $rank = self::RANK_NAMES[substr($code, 1)] ?? (int) substr($code, 1);

    return ['id' => array_search($suit, ['C', 'D', 'H', 'S'], true) * 100 + $rank, 'suit' => $suit, 'rank' => $rank];
  }

  /**
   * A hand written spades.hearts.diamonds.clubs, `T` for ten, `-` for a
   * void: `'AKQ2.KJ3.Q42.32'`.
   *
   * @return list<array{id: int, suit: string, rank: int}>
   */
  private function cards(string $hand): array
  {
    $cards = [];

    foreach (array_combine(['S', 'H', 'D', 'C'], explode('.', $hand)) as $suit => $ranks) {
      foreach (str_split($ranks === '-' ? '' : $ranks) as $rank) {
        if ($rank !== '') {
          $cards[] = $this->card($suit.$rank);
        }
      }
    }

    return $cards;
  }

  private function hand(string $hand): RobotHand
  {
    return new RobotHand($this->cards($hand));
  }

  /**
   * A `Card` model for the rules in `CardPlayService`.
   *
   * @param  array{id: int, suit: string, rank: int}  $card
   */
  private function model(array $card): Card
  {
    $model = new Card(['suit' => $card['suit'], 'rank' => $card['rank']]);
    $model->id = $card['id'];

    return $model;
  }

  /**
   * A `Bid` model for the rules in `AuctionService`.
   */
  private function bid(string $name): Bid
  {
    $special = in_array($name, [Bid::PASS, Bid::DOUBLE, Bid::REDOUBLE], true);

    return new Bid([
      'suit' => $name,
      'special' => $special,
      'level' => $special ? null : (int) $name[0],
      'strain' => $special ? null : substr($name, 1),
    ]);
  }

  /**
   * Calls named in turn from `$dealer`: `['1H', 'P', '2H']`.
   *
   * @param  list<string>  $names
   * @return list<array{seat: string, call: string}>
   */
  private function calls(string $dealer, array $names): array
  {
    $calls = [];
    $seat = $dealer;

    foreach ($names as $name) {
      $calls[] = ['seat' => $seat, 'call' => $name];
      $seat = Seats::next($seat);
    }

    return $calls;
  }

  /**
   * A shuffled deal: 13 cards a seat.
   *
   * @return array<string, list<array{id: int, suit: string, rank: int}>>
   */
  private function deal(): array
  {
    $deck = [];

    foreach (['S', 'H', 'D', 'C'] as $suit) {
      foreach (RobotHand::RANKS as $rank) {
        $deck[] = ['id' => array_search($suit, ['C', 'D', 'H', 'S'], true) * 100 + $rank, 'suit' => $suit, 'rank' => $rank];
      }
    }

    shuffle($deck);

    return array_combine(Seats::SEATS, array_chunk($deck, 13));
  }
}

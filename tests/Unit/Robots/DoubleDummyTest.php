<?php

namespace Tests\Unit\Robots;

use App\auxiliary\Seats;
use App\Robots\DoubleDummy;
use App\Robots\PlayView;
use App\Robots\RobotHand;
use PHPUnit\Framework\TestCase;

/**
 * The double dummy solver, against positions worked out by hand and against
 * a plain minimax over random small endings.
 */
class DoubleDummyTest extends TestCase
{
  use MakesCards;

  public function test_a_finesse_that_works_and_one_that_doesnt(): void
  {
    // S leads a heart towards N's A-Q; the king is with W, who plays before N
    $hands = $this->hands(['N' => '-.AQ.-.-', 'E' => '-.-.-.32', 'S' => '-.32.-.-', 'W' => '-.K4.-.-']);
    $this->assertSame(2, DoubleDummy::tricks($hands, null, [], 'S', 'N'));

    // with the king behind the A-Q, E takes one
    $hands = $this->hands(['N' => '-.AQ.-.-', 'E' => '-.K4.-.-', 'S' => '-.32.-.-', 'W' => '-.-.-.32']);
    $this->assertSame(1, DoubleDummy::tricks($hands, null, [], 'S', 'N'));
    $this->assertSame(1, DoubleDummy::tricks($hands, null, [], 'S', 'E'));
  }

  public function test_trumps_and_a_trick_in_progress(): void
  {
    // spades are trumps: W ruffs N's club winner unless N draws the trump
    $hands = $this->hands(['N' => 'A.-.-.A', 'E' => '-.32.-.-', 'S' => '-.-.32.-', 'W' => '2.4.-.-']);
    $this->assertSame(2, DoubleDummy::tricks($hands, 'S', [], 'N', 'N'));

    // with the club already led, W ruffs it
    $trick = [['seat' => 'N', 'card' => $this->card('CA')]];
    $hands['N'] = $this->cards('A.-.-.-');
    $this->assertSame(1, DoubleDummy::tricks($hands, 'S', $trick, 'E', 'N'));
  }

  public function test_card_values_for_the_seat_to_play(): void
  {
    // N, on lead with the ♠A and a low club, takes two tricks only by
    // cashing the ace first: after the club the defenders take both
    $hands = $this->hands(['N' => 'A.-.-.2', 'E' => '-.-.-.AK', 'S' => '-.-.-.QJ', 'W' => 'KQ.-.-.-']);
    $values = DoubleDummy::cardValues($hands, null, [], 'N', 'N');

    $this->assertSame([$this->card('SA')['id'] => 1, $this->card('C2')['id'] => 0], $values);
  }

  public function test_it_agrees_with_a_plain_minimax_on_random_endings(): void
  {
    mt_srand(38);

    for ($deal = 0; $deal < 60; $deal++) {
      $size = 1 + $deal % 3;
      $deck = $this->deck();
      shuffle($deck);
      $hands = array_combine(Seats::SEATS, array_chunk(array_slice($deck, 0, 4 * $size), $size));
      $trump = [null, 'S', 'H'][$deal % 3];
      $leader = Seats::SEATS[$deal % 4];

      $this->assertSame(
        $this->minimax($hands, $trump, [], $leader, 'N'),
        DoubleDummy::tricks($hands, $trump, [], $leader, 'N'),
        "deal $deal",
      );
    }
  }

  /**
   * The tricks N-S take, trying every legal card of every hand.
   *
   * @param  array<string, list<array{id: int, suit: string, rank: int}>>  $hands
   * @param  list<array{seat: string, card: array{id: int, suit: string, rank: int}}>  $trick
   */
  private function minimax(array $hands, ?string $trump, array $trick, string $turn, string $side): int
  {
    if ($hands[$turn] === [] && $trick === []) {
      return 0;
    }

    $cards = $hands[$turn];

    if ($trick !== []) {
      $follow = array_values(array_filter($cards, fn ($card) => $card['suit'] === $trick[0]['card']['suit']));
      $cards = $follow === [] ? $cards : $follow;
    }

    $values = [];

    foreach ($cards as $card) {
      $next = $hands;
      $next[$turn] = array_values(array_filter($hands[$turn], fn ($held) => $held !== $card));
      $played = [...$trick, ['seat' => $turn, 'card' => $card]];

      if (count($played) < 4) {
        $values[] = $this->minimax($next, $trump, $played, Seats::next($turn), $side);

        continue;
      }

      $winner = PlayView::winning($played, $trump)['seat'];
      $won = in_array($winner, ['N', 'S'], true) ? 1 : 0;
      $values[] = $won + $this->minimax($next, $trump, [], $winner, $side);
    }

    return in_array($turn, ['N', 'S'], true) ? max($values) : min($values);
  }

  /**
   * @param  array<string, string>  $hands
   * @return array<string, list<array{id: int, suit: string, rank: int}>>
   */
  private function hands(array $hands): array
  {
    return array_map(fn ($hand) => $this->cards($hand), $hands);
  }

  /**
   * @return list<array{id: int, suit: string, rank: int}>
   */
  private function deck(): array
  {
    $deck = [];

    foreach (RobotHand::SUITS as $suit) {
      foreach (RobotHand::RANKS as $rank) {
        $deck[] = ['id' => array_search($suit, ['C', 'D', 'H', 'S'], true) * 100 + $rank, 'suit' => $suit, 'rank' => $rank];
      }
    }

    return $deck;
  }
}

<?php

namespace Tests\Unit\Robots;

use App\auxiliary\Seats;
use App\Robots\RobotBidder;
use App\Robots\RobotHand;
use App\Services\AuctionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The robots' bidding (`docs/ROBOTS.md`), over in-memory hands: no
 * database. Hands are spades.hearts.diamonds.clubs.
 */
class RobotBidderTest extends TestCase
{
  use MakesCards;

  /**
   * calls so far from dealer N, the robot's hand, its call
   */
  public static function openings(): array
  {
    return [
      '1NT with 15-17 balanced' => [[], 'AKQ2.KJ3.Q42.J32', '1NT'],
      '2NT with 20-21 balanced' => [[], 'AKQ2.AKJ.Q42.J32', '2NT'],
      'a five-card major' => [[], 'AKJ32.K32.Q2.432', '1S'],
      'spades with 5-5 in the majors' => [[], 'AK432.KQ432.2.2', '1S'],
      'diamonds with 4-4 in the minors' => [['P'], 'AK2.K3.Q432.J432', '1D'],
      'clubs with 3-3 in the minors' => [['P', 'P'], 'AK32.K32.Q32.J32', '1C'],
      'the longer minor' => [[], 'AK2.K32.J2.Q5432', '1C'],
      'under 12 passes' => [['P', 'P', 'P'], 'AK32.K32.432.432', 'P'],
      'a 22+ count opens one of a suit' => [[], 'AKQ2.AKQJ.AQ2.32', '1D'],
    ];
  }

  public static function overcalls(): array
  {
    return [
      'a five-card suit at the 1 level with 8+' => [['1D'], 'KQJ32.K32.32.432', '1S'],
      'not at the 2 level under 11' => [['1S'], 'K32.KQJ32.32.432', 'P'],
      'at the 2 level with 11+' => [['1S'], 'K32.AQJ32.K2.432', '2H'],
      '1NT with 15-18 balanced and a stopper' => [['1H'], 'KJ2.A32.KQ32.K32', '1NT'],
      'no 1NT without a stopper in their suit' => [['1H'], 'KJ2.432.AKQ2.KQ2', 'P'],
      'never in their suit' => [['1S'], 'AKQ32.K32.32.432', 'P'],
      'no four-card overcall' => [['1C'], 'AKQ2.K32.432.432', 'P'],
    ];
  }

  public static function responses(): array
  {
    return [
      '2M with 6-10 and support' => [['1H', 'P'], 'K32.Q432.J432.32', '2H'],
      '3M with 11-12' => [['1H', 'P'], 'K32.KQ32.K432.32', '3H'],
      '4M with 13+' => [['1H', 'P'], 'A32.KQ32.K432.Q2', '4H'],
      'hearts first with 4-4 majors over a minor' => [['1D', 'P'], 'KJ32.Q432.32.432', '1H'],
      'a five-card major at the 1 level' => [['1C', 'P'], 'KJ432.Q4.32.5432', '1S'],
      '1NT with 6-10 and nothing to show' => [['1S', 'P'], 'K2.Q432.J432.432', '1NT'],
      '2NT with 13-15 balanced' => [['1S', 'P'], 'K2.AQ432.KJ2.432', '2NT'],
      'a 2-level new suit with 11+' => [['1S', 'P'], 'K2.AQ432.KJ32.43', '2H'],
      'pass under 6' => [['1S', 'P'], 'Q2.J432.5432.432', 'P'],
      'pass over 1NT with 7' => [['1NT', 'P'], 'K2.Q32.Q432.5432', 'P'],
      '2NT over 1NT with 8-9' => [['1NT', 'P'], 'K2.Q32.Q432.J432', '2NT'],
      '3NT over 1NT with 10-15' => [['1NT', 'P'], 'K2.K32.Q432.Q432', '3NT'],
      '4M over 1NT with a six-card major' => [['1NT', 'P'], 'KQ5432.Q32.J2.43', '4S'],
      '3NT over 2NT with 4+' => [['2NT', 'P'], 'K32.Q32.5432.432', '3NT'],
      'pass over 2NT under 4' => [['2NT', 'P'], 'K32.432.5432.432', 'P'],
      'a raise still made over an overcall' => [['1H', '1S'], 'K32.Q432.J432.32', '2H'],
      'a bid the overcall made illegal is a pass' => [['1S', '3H'], 'K2.Q432.J432.432', 'P'],
    ];
  }

  public static function rebids(): array
  {
    return [
      'raise the response with four' => [['1C', 'P', '1H', 'P'], 'A2.KJ32.A2.K5432', '2H'],
      'jump raise with 16-18' => [['1C', 'P', '1H', 'P'], 'A2.KQJ2.A2.K5432', '3H'],
      '1NT with 12-14 balanced' => [['1D', 'P', '1S', 'P'], 'K2.AJ3.Q432.K432', '1NT'],
      'a six-card suit again' => [['1H', 'P', '2C', 'P'], 'A2.KQJ432.K32.32', '2H'],
      '2NT with 12-14 after a 2-level response' => [['1S', 'P', '2D', 'P'], 'AK432.Q32.K32.32', '2NT'],
      'a new lower suit at the 2 level' => [['1D', 'P', '1H', 'P'], 'A2.32.AK432.KQ32', '2C'],
      'a new suit at the 1 level' => [['1C', 'P', '1H', 'P'], 'KQ32.2.A32.KJ432', '1S'],
      'no reverse under 17' => [['1D', 'P', '1S', 'P'], '32.KQ32.AK432.32', '2D'],
    ];
  }

  public static function placements(): array
  {
    return [
      'invite with 15-18 opposite a single raise' => [['1H', 'P', '2H', 'P'], 'A2.AKJ32.KQ32.32', '3H'],
      'game with 19+ opposite a single raise' => [['1H', 'P', '2H', 'P'], 'A2.AKJ32.KQ2.AQ2', '4H'],
      'pass a single raise with a minimum' => [['1H', 'P', '2H', 'P'], 'A2.KJ432.K32.Q32', 'P'],
      'accept an invitation with the top half' => [['1H', 'P', '2H', 'P', '3H', 'P'], 'K32.Q432.K432.32', '4H'],
      'decline it with the bottom half' => [['1H', 'P', '2H', 'P', '3H', 'P'], 'K32.Q432.J432.32', 'P'],
      'accept 2NT over 1NT with 16-17' => [['1NT', 'P', '2NT', 'P'], 'AK2.KQ3.Q42.Q32', '3NT'],
      'decline it with 15' => [['1NT', 'P', '2NT', 'P'], 'AK2.KQ3.Q42.J32', 'P'],
      'pass 1NT with a minimum' => [['1S', 'P', '1NT', 'P'], 'AK432.Q32.K32.32', 'P'],
      'game in 3NT opposite a 1NT rebid with 13+' => [['1C', 'P', '1H', 'P', '1NT', 'P'], 'K32.AQ432.K32.Q2', '3NT'],
      'game in the fit opposite a raise with 13+' => [['1C', 'P', '1H', 'P', '2H', 'P'], 'K32.AQ432.K32.Q2', '4H'],
      'never over the opponents' => [['1H', 'P', '2H', '3C'], 'A2.AKJ32.KQ2.AQ2', 'P'],
      'nothing after game' => [['1H', 'P', '4H', 'P'], 'A2.AKJ32.KQ2.AQ2', 'P'],
      'pass a partner who overcalled' => [['1C', '1S', 'P'], 'K32.Q432.K432.32', 'P'],
    ];
  }

  #[DataProvider('openings')]
  #[DataProvider('overcalls')]
  #[DataProvider('responses')]
  #[DataProvider('rebids')]
  #[DataProvider('placements')]
  public function test_the_call(array $names, string $hand, string $expected): void
  {
    $calls = $this->calls('N', $names);
    $seat = $calls === [] ? 'N' : Seats::next(end($calls)['seat']);

    $this->assertSame($expected, RobotBidder::choose($this->hand($hand), $calls, $seat));
  }

  public function test_partner_is_read_back_from_their_bids(): void
  {
    $calls = $this->calls('N', ['1H', 'P', '2H', 'P', '3H', 'P']);

    $opener = RobotBidder::shown($calls, 'N');
    $responder = RobotBidder::shown($calls, 'S');

    $this->assertSame([12, 21, 5], [$opener['min'], $opener['max'], $opener['lengths']['H']]);
    $this->assertTrue($opener['invite']);
    $this->assertSame([6, 10, 3], [$responder['min'], $responder['max'], $responder['lengths']['H']]);
    $this->assertFalse($responder['invite']);

    $this->assertSame(RobotBidder::UNKNOWN_MAX, RobotBidder::shown($calls, 'E')['max']);
  }

  public function test_the_cheapest_bid_in_a_strain(): void
  {
    $this->assertSame('1C', RobotBidder::cheapest('C', []));
    $this->assertSame('1S', RobotBidder::cheapest('S', $this->calls('N', ['1H'])));
    $this->assertSame('2H', RobotBidder::cheapest('H', $this->calls('N', ['1S', 'X'])));
    $this->assertNull(RobotBidder::cheapest('C', $this->calls('N', ['7NT'])));
  }

  /**
   * Four robots bid a few hundred random deals: every call is legal by the
   * real rules, never a double, and every auction ends.
   */
  public function test_every_call_is_legal_and_every_auction_ends(): void
  {
    for ($board = 0; $board < 300; $board++) {
      $hands = array_map(fn ($cards) => new RobotHand($cards), $this->deal());
      $dealer = Seats::SEATS[$board % 4];
      $calls = [];
      $made = [];

      while (! AuctionService::isOver($made)) {
        $this->assertLessThan(40, count($calls), 'the auction never ends');

        $seat = AuctionService::nextToCall($made, $dealer);
        $call = RobotBidder::choose($hands[$seat], $calls, $seat);

        $this->assertNotContains($call, ['X', 'XX']);
        $this->assertNull(AuctionService::illegalReason($made, $seat, $this->bid($call)), "$call after ".json_encode($calls));

        $calls[] = ['seat' => $seat, 'call' => $call];
        $made[] = ['seat' => $seat, 'bid' => $this->bid($call)];
      }

      // robots stop at game: no slams
      $result = AuctionService::result($made);
      $this->assertTrue($result === null || $result['bid']->level <= 5);
    }
  }
}

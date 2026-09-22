<?php

namespace Tests\Unit;

use App\auxiliary\Seats;
use App\Models\Bid;
use App\Services\AuctionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The bidding rules on their own, over in-memory calls: no database.
 */
class AuctionServiceTest extends TestCase
{
  public function test_the_dealer_calls_first_then_clockwise(): void
  {
    $this->assertSame('E', AuctionService::nextToCall([], 'E'));
    $this->assertSame('S', AuctionService::nextToCall($this->calls('E', ['1C']), 'E'));
    $this->assertSame('N', AuctionService::nextToCall($this->calls('E', ['1C', 'P', 'P']), 'E'));
  }

  public function test_nobody_calls_after_the_auction_ends(): void
  {
    $this->assertNull(AuctionService::nextToCall($this->calls('N', ['1C', 'P', 'P', 'P']), 'N'));
    $this->assertNull(AuctionService::nextToCall($this->calls('N', ['P', 'P', 'P', 'P']), 'N'));
  }

  /**
   * dealer, calls so far, next call, expected reason (null = legal)
   */
  public static function legality(): array
  {
    return [
      'pass at the start' => ['N', [], 'P', null],
      'opening bid' => ['N', [], '1C', null],
      'higher level' => ['N', ['1NT'], '2C', null],
      'same level, higher strain' => ['N', ['1H'], '1S', null],
      'same bid' => ['N', ['1H'], '1H', '1H is not higher than the last bid, 1H.'],
      'lower strain' => ['N', ['1H'], '1D', '1D is not higher than the last bid, 1H.'],
      'lower level' => ['N', ['2C'], '1NT', '1NT is not higher than the last bid, 2C.'],
      'too low after passes' => ['N', ['1H', 'P', 'P'], '1C', '1C is not higher than the last bid, 1H.'],
      'too low after X' => ['N', ['1H', 'X'], '1D', '1D is not higher than the last bid, 1H.'],
      'X an opponent' => ['N', ['1H'], 'X', null],
      'X an opponent after passes' => ['N', ['1H', 'P', 'P'], 'X', null],
      'X with no bid' => ['N', [], 'X', 'There is no bid to double.'],
      'X after passes only' => ['N', ['P', 'P'], 'X', 'There is no bid to double.'],
      'X your partner' => ['N', ['1H', 'P'], 'X', "You can't double your own side's bid."],
      'X a doubled bid' => ['N', ['1H', 'X'], 'X', 'That bid is already doubled.'],
      'X a doubled bid after passes' => ['N', ['1H', 'X', 'P', 'P'], 'X', 'That bid is already doubled.'],
      'X a redoubled bid' => ['N', ['1H', 'X', 'XX'], 'X', 'That bid is already redoubled.'],
      'XX straight away' => ['N', ['1H', 'X'], 'XX', null],
      'XX after passes' => ['N', ['1H', 'X', 'P', 'P'], 'XX', null],
      'XX with no X' => ['N', ['1H'], 'XX', 'Only a double can be redoubled.'],
      'XX with nothing' => ['N', [], 'XX', 'Only a double can be redoubled.'],
      'XX your own side\'s X' => ['N', ['1H', 'X', 'P'], 'XX', "You can't redouble your own side's double."],
      'second XX' => ['N', ['1H', 'X', 'XX'], 'XX', 'That bid is already redoubled.'],
      'second XX after passes' => ['N', ['1H', 'X', 'XX', 'P', 'P'], 'XX', 'That bid is already redoubled.'],
      'bid after XX' => ['N', ['1H', 'X', 'XX'], '2C', null],
      'X a new bid after XX' => ['N', ['1H', 'X', 'XX', '2C'], 'X', null],
      'call after the end' => ['N', ['1H', 'P', 'P', 'P'], 'P', 'The auction has ended.'],
    ];
  }

  #[DataProvider('legality')]
  public function test_call_legality(string $dealer, array $made, string $next, ?string $reason): void
  {
    $calls = $this->calls($dealer, $made);
    $seat = AuctionService::nextToCall($calls, $dealer) ?? 'N';

    $this->assertSame($reason, AuctionService::illegalReason($calls, $seat, $this->bid($next)));
  }

  public function test_the_end_of_the_auction(): void
  {
    $this->assertFalse(AuctionService::isOver($this->calls('N', [])));
    $this->assertFalse(AuctionService::isOver($this->calls('N', ['P', 'P', 'P'])));
    $this->assertFalse(AuctionService::isOver($this->calls('N', ['1C', 'P', 'P'])));
    $this->assertFalse(AuctionService::isOver($this->calls('N', ['P', '1C', 'P', 'P'])));
    $this->assertTrue(AuctionService::isOver($this->calls('N', ['P', 'P', 'P', 'P'])));
    $this->assertTrue(AuctionService::isOver($this->calls('N', ['1C', 'P', 'P', 'P'])));
    $this->assertTrue(AuctionService::isOver($this->calls('N', ['P', '1C', 'P', 'P', 'P'])));
    $this->assertTrue(AuctionService::isOver($this->calls('N', ['1C', 'X', 'P', 'P', 'P'])));
    $this->assertTrue(AuctionService::isOver($this->calls('N', ['1C', 'X', 'XX', 'P', 'P', 'P'])));
  }

  public function test_a_passed_out_board_has_no_contract(): void
  {
    $this->assertNull(AuctionService::result($this->calls('N', ['P', 'P', 'P', 'P'])));
  }

  /**
   * dealer, calls, contract, doubled, declarer
   */
  public static function results(): array
  {
    return [
      'simple' => ['N', ['1H', 'P', 'P', 'P'], '1H', 0, 'N'],
      'partner raises: first to name the strain declares' => ['N', ['1H', 'P', '4H', 'P', 'P', 'P'], '4H', 0, 'N'],
      'partner names the strain first' => ['N', ['1C', 'P', '1H', 'P', '4H', 'P', 'P', 'P'], '4H', 0, 'S'],
      'opponents named it first' => ['N', ['1H', '2H', '3C', 'P', '3H', 'P', 'P', 'P'], '3H', 0, 'N'],
      'opponents win the contract' => ['N', ['1H', '1S', 'P', '4S', 'P', 'P', 'P'], '4S', 0, 'E'],
      'doubled' => ['N', ['1H', 'X', 'P', 'P', 'P'], '1H', 1, 'N'],
      'doubled after passes' => ['N', ['1H', 'P', 'P', 'X', 'P', 'P', 'P'], '1H', 1, 'N'],
      'redoubled' => ['N', ['1H', 'X', 'XX', 'P', 'P', 'P'], '1H', 2, 'N'],
      'redoubled after passes' => ['N', ['1H', 'X', 'P', 'P', 'XX', 'P', 'P', 'P'], '1H', 2, 'N'],
      'a new bid clears the double' => ['N', ['1H', 'X', 'XX', '2C', 'P', 'P', 'P'], '2C', 0, 'W'],
      'dealer passes first' => ['E', ['P', '1NT', 'P', '3NT', 'P', 'P', 'P'], '3NT', 0, 'S'],
    ];
  }

  #[DataProvider('results')]
  public function test_the_result(string $dealer, array $made, string $contract, int $doubled, string $declarer): void
  {
    $result = AuctionService::result($this->calls($dealer, $made));

    $this->assertSame($contract, $result['bid']->suit);
    $this->assertSame($doubled, $result['doubled']);
    $this->assertSame($declarer, $result['declarer']);
  }

  /**
   * Calls by name, made clockwise from the dealer.
   *
   * @param  list<string>  $names
   * @return list<array{seat: string, bid: Bid}>
   */
  private function calls(string $dealer, array $names): array
  {
    $calls = [];
    $seat = $dealer;

    foreach ($names as $name) {
      $calls[] = ['seat' => $seat, 'bid' => $this->bid($name)];
      $seat = Seats::next($seat);
    }

    return $calls;
  }

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
}

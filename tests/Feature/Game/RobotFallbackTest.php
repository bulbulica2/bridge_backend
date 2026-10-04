<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\PlayingUpdated;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Cardplay;
use App\Models\Table;
use App\Models\User;
use App\Services\AuctionService;
use App\Services\BoardSelectionService;
use App\Services\CardPlayService;
use App\Services\ClaimService;
use App\Services\PlayingStateService;
use App\Services\RobotService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The robots' bidder and card player only pick legal moves, but
 * `RobotService` doesn't trust them: a call the rules refuse becomes a pass,
 * a card the rules refuse the first legal card. A robot's choice is
 * swapped here for one the rules refuse.
 */
class RobotFallbackTest extends TestCase
{
  use RefreshDatabase;

  private PlayingStateService $state;

  private User $human;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    // only the robot under test moves
    Event::fake([PlayingUpdated::class]);

    $this->state = app(PlayingStateService::class);
    $this->human = User::factory()->create();
  }

  public static function badCalls(): array
  {
    return [
      'no such call' => ['8NT'],
      'a redouble with nothing doubled' => ['XX'],
    ];
  }

  #[DataProvider('badCalls')]
  public function test_a_call_the_rules_refuse_becomes_a_pass(string $choice): void
  {
    // board 1: North deals, and a robot sits there
    $table = $this->robotTable(human: 'S');
    $playing = $this->state->currentPlaying($table);
    $north = $playing->seats->firstWhere('seat', 'N')->user;

    $this->assertTrue($north->is_robot);
    $this->assertSame($north->id, $this->state->actingUserId($playing));

    $this->assertTrue($this->robots(call: $choice)->act($table));

    $calls = $this->state->calls($playing->refresh());
    $this->assertCount(1, $calls);
    $this->assertSame('N', $calls[0]['seat']);
    $this->assertTrue($calls[0]['bid']->isPass());
    // the alert went with the refused call
    $this->assertFalse($playing->auctions()->sole()->alerted);
  }

  public static function badCards(): array
  {
    return [
      'a card East does not hold' => [true],
      'no such card' => [false],
    ];
  }

  #[DataProvider('badCards')]
  public function test_a_card_the_rules_refuse_becomes_the_first_legal_card(bool $declarers): void
  {
    // North declares 1C, so East, a robot, makes the opening lead
    $table = $this->robotTable(human: 'N');
    $playing = $this->state->currentPlaying($table);
    $this->bidOneClubAsNorth($table, $playing);

    $playing->refresh();
    $this->assertSame('E', $this->state->turn($playing));

    $choice = $declarers ? $this->state->hand($playing, 'N')[0]['id'] : 0;
    $eastHand = array_column($this->state->hand($playing, 'E'), 'id');

    $this->assertTrue($this->robots(card: $choice)->act($table));

    $played = Cardplay::where('board_table_id', $playing->id)->sole();
    $this->assertSame('E', $played->seat);
    $this->assertContains($played->card_id, $eastHand);
  }

  /**
   * A `RobotService` whose bidder answers `$call` and whose card player
   * answers `$card`, when given.
   */
  private function robots(?string $call = null, ?int $card = null): RobotService
  {
    return new class($call, $card) extends RobotService
    {
      public function __construct(private ?string $call, private ?int $card)
      {
        parent::__construct(
          app(TableSeatService::class),
          app(PlayingStateService::class),
          app(AuctionService::class),
          app(CardPlayService::class),
          app(ClaimService::class),
          app(BoardSelectionService::class),
        );
      }

      protected function chooseCall(array $state): array
      {
        return $this->call === null
          ? parent::chooseCall($state)
          : ['call' => $this->call, 'alert' => true, 'explanation' => 'Made up'];
      }

      protected function chooseCard(array $state): int
      {
        return $this->card ?? parent::chooseCard($state);
      }
    };
  }

  /**
   * The human in `$human`, robots in the other seats, board 1 dealt.
   */
  private function robotTable(string $human): Table
  {
    $table = Table::create(['created_by' => $this->human->id, 'moderated_by' => $this->human->id]);
    app(TableSeatService::class)->seat($table, $this->human, $human);

    foreach (array_diff(Seats::SEATS, [$human]) as $seat) {
      app(RobotService::class)->seatRobot($table, $seat, $this->human);
    }

    $this->startBoard($table);

    return $table->refresh();
  }

  /**
   * North opens 1C and everyone else passes, through the auction service.
   */
  private function bidOneClubAsNorth(Table $table, BoardTable $playing): void
  {
    $pass = Bid::where('suit', Bid::PASS)->firstOrFail();
    $opening = Bid::where('suit', '1C')->firstOrFail();

    while (! AuctionService::isOver($this->state->calls($playing->refresh()))) {
      $seat = AuctionService::nextToCall($this->state->calls($playing), $playing->board->dealer);
      $bid = $seat === 'N' && $playing->auctions()->count() === 0 ? $opening : $pass;

      app(AuctionService::class)->call($table, $playing->seats->firstWhere('seat', $seat)->user, $bid);
    }
  }
}

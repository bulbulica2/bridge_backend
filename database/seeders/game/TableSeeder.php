<?php

namespace Database\Seeders\game;

use App\auxiliary\Seats;
use App\Events\HandDealt;
use App\Events\PlayingUpdated;
use App\Events\TableUpdated;
use App\Models\Table;
use App\Models\User;
use App\Services\CardPlayService;
use App\Services\ClaimService;
use App\Services\PlayingStateService;
use App\Services\TableSeatService;
use Database\Seeders\AuctionSeeder;
use Database\Seeders\CardplaySeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;

class TableSeeder extends Seeder
{
  public function __construct(
    private TableSeatService $seats,
    private ClaimService $claims,
    private PlayingStateService $state,
  ) {}

  /**
   * One table in each phase a player can find a board in, all reached
   * through the game services, so every seat, call and card is one the API
   * would have accepted. Needs BoardSeeder and UserSeeder to have run.
   */
  public function run(): void
  {
    // the services broadcast every seat, call and card; nobody is listening
    // to a fresh database, so keep those jobs off the queue
    Event::fakeFor(fn () => $this->seedTables(), [TableUpdated::class, PlayingUpdated::class, HandDealt::class]);
  }

  private function seedTables(): void
  {
    $admin = User::where('email', UserSeeder::ADMIN_EMAIL)->firstOrFail();

    // the admin's own table, left with the admin to call: a ready case for
    // the frontend
    $table = $this->table('Your call', [$admin, ...User::factory(3)->create()]);
    $this->callWith(AuctionSeeder::class, ['table' => $table, 'stopAt' => $this->seatOf($table, $admin)]);

    $table = $this->table('Bidding', User::factory(4)->create());
    $this->callWith(AuctionSeeder::class, ['table' => $table, 'stopAt' => Arr::random(Seats::SEATS)]);

    // stopped after the opening lead, so dummy is face up, and before the last card
    $table = $this->table('Playing', User::factory(4)->create());
    $this->callWith(AuctionSeeder::class, ['table' => $table]);
    $this->callWith(CardplaySeeder::class, ['table' => $table, 'cards' => mt_rand(1, CardPlayService::TRICKS * 4 - 1)]);

    $table = $this->table('Finished', User::factory(4)->create());
    $this->callWith(AuctionSeeder::class, ['table' => $table]);
    $this->callWith(CardplaySeeder::class, ['table' => $table]);

    // ended mid-play by declarer's claim, which both defenders accepted
    $table = $this->table('Claimed', User::factory(4)->create());
    $this->callWith(AuctionSeeder::class, ['table' => $table]);
    $this->callWith(CardplaySeeder::class, ['table' => $table, 'cards' => mt_rand(1, CardPlayService::TRICKS * 4 - 1)]);
    $this->claim($table);

    $table = $this->table('Passed out', User::factory(4)->create());
    $this->callWith(AuctionSeeder::class, ['table' => $table, 'end' => AuctionSeeder::PASSED_OUT]);

    // short of players, so no board yet
    $this->table('Waiting for players', User::factory(2)->create());
  }

  /**
   * A table created by its first player, as `POST /tables` does, with the
   * players seated clockwise from N through `TableSeatService`: a fourth one
   * deals it a board and opens its playing.
   *
   * @param  iterable<User>  $players
   */
  private function table(string $name, iterable $players): Table
  {
    $players = collect($players)->values();

    $table = Table::create([
      'name' => $name,
      'created_by' => $players[0]->id,
      'moderated_by' => $players[0]->id,
    ]);

    foreach ($players as $index => $player) {
      $this->seats->seat($table, $player, Seats::SEATS[$index]);
    }

    return $table;
  }

  /**
   * Declarer claims a random share of the tricks still to play, through
   * `ClaimService`, and both defenders accept, which finishes the board.
   */
  private function claim(Table $table): void
  {
    $playing = $this->state->currentPlaying($table);
    $declarer = $playing->declarer_seat;
    $player = fn (string $seat) => $playing->seats->firstWhere('seat', $seat)->user;

    $this->claims->claim($table, $player($declarer), mt_rand(0, ClaimService::remaining($this->state->plays($playing))));

    foreach (ClaimService::responders($declarer, $declarer) as $seat) {
      $this->claims->respond($table, $player($seat), true);
    }
  }

  private function seatOf(Table $table, User $user): string
  {
    return $table->seats()->where('user_id', $user->id)->value('seat');
  }
}

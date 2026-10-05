<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Jobs\SolveDoubleDummyTable;
use App\Jobs\SolveOpeningLeads;
use App\Models\Bid;
use App\Models\Board;
use App\Models\BoardDoubleDummy;
use App\Models\BoardLeadAnalysis;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\Table;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\BoardSelectionService;
use App\Services\CardPlayService;
use App\Services\DoubleDummyService;
use App\Services\PlayingStateService;
use App\Services\TableSeatService;
use App\Solvers\DdsSolver;
use App\Solvers\DoubleDummySolver;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeDoubleDummySolver;
use Tests\TestCase;

class DoubleDummyTest extends TestCase
{
  use RefreshDatabase;

  // faked alone: the rest of the queue runs as in every other test
  private const JOBS = [SolveDoubleDummyTable::class, SolveOpeningLeads::class];

  private FakeDoubleDummySolver $solver;

  private Table $table;

  private BoardTable $playing;

  /**
   * @var array<string, User>
   */
  private array $players;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    $this->solver = new FakeDoubleDummySolver;
    $this->app->instance(DoubleDummySolver::class, $this->solver);

    // four players fill a table and are dealt a board
    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = User::factory()->create();
      app(TableSeatService::class)->seat($this->table, $this->players[$seat], $seat);
    }

    $this->playing = $this->startBoard($this->table);
  }

  public function test_dealing_a_board_solves_its_table_once(): void
  {
    $this->assertSame(1, $this->solver->tables);
    $this->assertSame(FakeDoubleDummySolver::TABLE, BoardDoubleDummy::where('board_id', $this->playing->board_id)->sole()->tricks);

    // dealt again, or the job run again: it is stored already
    app(DoubleDummyService::class)->queueTable($this->playing->board);
    (new SolveDoubleDummyTable($this->playing->board_id))->handle(app(DoubleDummyService::class));

    $this->assertSame(1, $this->solver->tables);
    $this->assertSame([], $this->solver->leads);
  }

  public function test_finishing_a_playing_solves_its_opening_leads_once_per_contract(): void
  {
    $declarer = $this->finishWithContract();
    $leader = Seats::next($declarer);

    $this->assertSame([[$declarer, 'C']], $this->solver->leads);

    // the leader's 13 cards in hand order, each with declarer's tricks after it
    $hand = app(PlayingStateService::class)->boardDeal($this->playing->board)[$leader];
    $analysis = BoardLeadAnalysis::sole();
    $this->assertSame([$this->playing->board_id, $declarer, 'C'], [$analysis->board_id, $analysis->declarer_seat, $analysis->strain]);
    $this->assertSame(
      array_map(fn ($card) => ['card_id' => $card['id'], 'tricks' => $card['rank'] - 2], $hand),
      $analysis->leads
    );

    // the same declarer and strain at another table, at another level: shared
    $this->finishElsewhere($declarer, '3C');
    $this->assertCount(1, $this->solver->leads);

    // another strain is another analysis
    $this->finishElsewhere($declarer, '1NT');
    $this->assertSame([[$declarer, 'C'], [$declarer, 'NT']], $this->solver->leads);
    $this->assertSame(2, BoardLeadAnalysis::count());

    // the job run again solves nothing
    (new SolveOpeningLeads($this->playing->board_id, $declarer, 'C'))->handle(app(DoubleDummyService::class));
    $this->assertCount(2, $this->solver->leads);
  }

  public function test_players_who_finished_the_board_see_its_table(): void
  {
    $this->finishWithContract();

    $this->doubleDummy('E')
      ->assertOk()
      ->assertJsonPath('message', 'Double dummy analysis retrieved successfully.')
      ->assertJsonPath('data.status', 'ready')
      ->assertJsonPath('data.table', FakeDoubleDummySolver::TABLE);
  }

  public function test_nobody_sees_it_before_finishing_the_board(): void
  {
    // seated at the board, mid-auction
    $this->doubleDummy('N')->assertForbidden();

    $this->finishWithContract();

    // not at this table, nor anywhere this board was finished
    $this->actingAs(User::factory()->create())
      ->getJson("/boards/{$this->playing->board_id}/double-dummy")
      ->assertForbidden();

    $this->actingAs($this->players['N'])->getJson('/boards/999999/double-dummy')->assertNotFound();

    $this->app['auth']->forgetGuards();
    $this->getJson("/boards/{$this->playing->board_id}/double-dummy")->assertUnauthorized();
  }

  public function test_the_review_carries_the_table_and_the_opening_leads(): void
  {
    $declarer = $this->finishWithContract();
    $hand = app(PlayingStateService::class)->boardDeal($this->playing->board)[Seats::next($declarer)];

    $response = $this->review('S')
      ->assertOk()
      ->assertJsonPath('data.double_dummy.status', 'ready')
      ->assertJsonPath('data.double_dummy.table', FakeDoubleDummySolver::TABLE)
      ->assertJsonCount(13, 'data.double_dummy.leads');

    $this->assertSame($hand, $response->json('data.double_dummy.leads.*.card'));
    $this->assertSame(
      array_map(fn ($card) => $card['rank'] - 2, $hand),
      $response->json('data.double_dummy.leads.*.tricks')
    );
  }

  public function test_a_passed_out_board_has_a_table_and_no_leads(): void
  {
    $this->callAll(['P', 'P', 'P', 'P']);

    $this->assertSame([], $this->solver->leads);

    $this->review('W')
      ->assertOk()
      ->assertJsonPath('data.double_dummy.status', 'ready')
      ->assertJsonPath('data.double_dummy.table', FakeDoubleDummySolver::TABLE)
      ->assertJsonPath('data.double_dummy.leads', null);
  }

  public function test_it_is_pending_until_the_queue_has_solved_it(): void
  {
    // a board dealt before the analysis existed: no table stored
    BoardDoubleDummy::query()->delete();
    Queue::fake(self::JOBS);

    $this->finishWithContract();
    Queue::assertPushed(SolveOpeningLeads::class, 1);

    // the read queues the missing table
    $this->doubleDummy('N')
      ->assertOk()
      ->assertJsonPath('data.status', 'pending')
      ->assertJsonPath('data.table', null);
    Queue::assertPushed(SolveDoubleDummyTable::class, 1);

    $this->review('N')
      ->assertOk()
      ->assertJsonPath('data.double_dummy.status', 'pending')
      ->assertJsonPath('data.double_dummy.table', null)
      ->assertJsonPath('data.double_dummy.leads', null);

    // one job each waits in the queue, however often it is read
    Queue::assertPushed(SolveDoubleDummyTable::class, 1);
    Queue::assertPushed(SolveOpeningLeads::class, 1);

    // the worker runs them
    Queue::pushed(SolveDoubleDummyTable::class)->each(fn ($job) => app()->call([$job, 'handle']));
    $this->review('N')
      ->assertJsonPath('data.double_dummy.status', 'pending')
      ->assertJsonPath('data.double_dummy.table', FakeDoubleDummySolver::TABLE)
      ->assertJsonPath('data.double_dummy.leads', null);

    Queue::pushed(SolveOpeningLeads::class)->each(fn ($job) => app()->call([$job, 'handle']));
    $this->review('N')
      ->assertJsonPath('data.double_dummy.status', 'ready')
      ->assertJsonCount(13, 'data.double_dummy.leads');
    $this->doubleDummy('N')->assertJsonPath('data.status', 'ready');
  }

  public function test_nothing_is_solved_without_a_solver(): void
  {
    BoardDoubleDummy::query()->delete();
    $this->app->forgetInstance(DoubleDummySolver::class);
    Queue::fake(self::JOBS);

    $this->finishWithContract();

    $this->doubleDummy('N')
      ->assertOk()
      ->assertJsonPath('data.status', 'unavailable')
      ->assertJsonPath('data.table', null);

    $this->review('N')
      ->assertJsonPath('data.double_dummy.status', 'unavailable')
      ->assertJsonPath('data.double_dummy.leads', null);

    Queue::assertNothingPushed();
  }

  public function test_solve_missing_queues_only_what_has_no_analysis(): void
  {
    // played before the solver existed: the dealer's 1C here, and the same
    // declarer and strain elsewhere, plus North's 2H, which has its leads
    $declarer = $this->finishWithContract();
    $this->finishElsewhere($declarer, '3C');
    $this->finishElsewhere('N', '2H');
    $this->assertSame(2, BoardLeadAnalysis::count());
    BoardDoubleDummy::query()->delete();
    BoardLeadAnalysis::where('strain', 'C')->delete();

    // a board with no cards (never dealt), and a dealt board with its
    // table, passed out at a table (no contract, so no leads)
    Board::factory()->create();
    $solved = app(BoardSelectionService::class)->dealBoard();
    BoardDoubleDummy::create(['board_id' => $solved->id, 'tricks' => FakeDoubleDummySolver::TABLE]);
    BoardTable::factory()->create(['board_id' => $solved->id, 'auction_ended_at' => now()])->finish(null);

    Queue::fake(self::JOBS);

    $this->artisan('dds:solve-missing')
      ->expectsOutputToContain('Queued 1 table and the opening leads of 1 contract.')
      ->assertSuccessful();

    Queue::assertPushed(SolveDoubleDummyTable::class, 1);
    Queue::assertPushed(SolveDoubleDummyTable::class, fn ($job) => $job->boardId === $this->playing->board_id);
    Queue::assertPushed(SolveOpeningLeads::class, 1);
    Queue::assertPushed(SolveOpeningLeads::class, fn ($job) => [$job->boardId, $job->declarer, $job->strain] === [$this->playing->board_id, $declarer, 'C']);

    // the worker solves them: nothing is left to queue
    Queue::pushed(SolveDoubleDummyTable::class)->each(fn ($job) => app()->call([$job, 'handle']));
    Queue::pushed(SolveOpeningLeads::class)->each(fn ($job) => app()->call([$job, 'handle']));
    $this->assertSame(['tables' => 0, 'leads' => 0], app(DoubleDummyService::class)->queueMissing());
  }

  public function test_solve_missing_needs_a_solver(): void
  {
    BoardDoubleDummy::query()->delete();
    $this->app->forgetInstance(DoubleDummySolver::class);
    Queue::fake(self::JOBS);

    $this->artisan('dds:solve-missing')
      ->expectsOutputToContain('There is no double dummy solver')
      ->assertFailed();

    Queue::assertNothingPushed();
  }

  public function test_dds_is_the_solver_when_dds_library_is_set(): void
  {
    $this->app->forgetInstance(DoubleDummySolver::class);

    // phpunit.xml leaves DDS_LIBRARY empty
    (new AppServiceProvider($this->app))->register();
    $this->assertFalse($this->app->bound(DoubleDummySolver::class));

    config(['bridge.dds_library' => '/usr/lib/x86_64-linux-gnu/libdds.so.0', 'bridge.dds_memory_mb' => 128, 'bridge.dds_threads' => 3]);
    (new AppServiceProvider($this->app))->register();

    // loaded only when it first solves something, then capped as configured
    $solver = app(DoubleDummySolver::class);
    $this->assertInstanceOf(DdsSolver::class, $solver);
    $this->assertSame([128, 3], (fn () => [$this->memoryMb, $this->threads])->call($solver));
  }

  private function doubleDummy(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->getJson("/boards/{$this->playing->board_id}/double-dummy");
  }

  private function review(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->getJson("/playings/{$this->playing->id}");
  }

  /**
   * The dealer opens 1C and declares it, a trick is played, and declarer's
   * claim of the rest is accepted. Returns declarer's seat.
   */
  private function finishWithContract(): string
  {
    $this->callAll(['1C', 'P', 'P', 'P']);
    $this->playCards(4);

    $declarer = $this->playing->board->dealer;
    $this->actingAs($this->players[$declarer])
      ->postJson("/tables/{$this->table->id}/claim", ['tricks' => 12])
      ->assertCreated();

    foreach ([Seats::next($declarer), Seats::partner(Seats::next($declarer))] as $defender) {
      $this->actingAs($this->players[$defender])
        ->postJson("/tables/{$this->table->id}/claim/response", ['accept' => true])
        ->assertOk();
    }

    $this->assertNotNull($this->playing->fresh()->finished_at);

    return $declarer;
  }

  /**
   * Another table's playing of the same board, finished in `$call` by
   * `$declarer`.
   */
  private function finishElsewhere(string $declarer, string $call): void
  {
    BoardTable::factory()->create([
      'board_id' => $this->playing->board_id,
      'contract_bid_id' => Bid::where('suit', $call)->value('id'),
      'doubled' => 0,
      'declarer_seat' => $declarer,
      'auction_ended_at' => now(),
    ])->finish(8);
  }

  /**
   * @param  list<string>  $calls  from the dealer round
   */
  private function callAll(array $calls): void
  {
    $seat = $this->playing->board->dealer;

    foreach ($calls as $call) {
      $this->actingAs($this->players[$seat])
        ->postJson("/tables/{$this->table->id}/calls", ['bid_id' => Bid::where('suit', $call)->value('id')])
        ->assertCreated();
      $seat = Seats::next($seat);
    }
  }

  /**
   * Play the next `$count` cards, each hand playing its first legal card.
   */
  private function playCards(int $count): void
  {
    $state = app(PlayingStateService::class);

    for ($i = 0; $i < $count; $i++) {
      $playing = $state->currentPlaying($this->table);
      $plays = $state->plays($playing);
      $turn = $state->turn($playing);

      $card = collect($state->hand($playing, $turn))
        ->map(fn ($held) => Card::find($held['id']))
        ->first(fn ($card) => CardPlayService::illegalReason($plays, $state->hand($playing, $turn), $card) === null);

      $this->actingAs(User::find($state->actingUserId($playing)))
        ->postJson("/tables/{$this->table->id}/cards", ['card_id' => $card->id])
        ->assertCreated();
    }
  }
}

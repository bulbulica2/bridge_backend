<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Models\Bid;
use App\Models\Board;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\User;
use App\Services\BoardSelectionService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardResultsTest extends TestCase
{
  use RefreshDatabase;

  private Board $board;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    $this->board = app(BoardSelectionService::class)->dealBoard();
  }

  public function test_results_list_every_finished_playing_with_matchpoints(): void
  {
    $a = $this->playing(620);
    $b = $this->playing(-100);
    $c = $this->playing(170);
    $d = $this->playing(620);

    // still being played somewhere else: not a result yet
    $this->playing(null, finished: false);

    $response = $this->actingAs($b->seats->first()->user)
      ->getJson("/boards/{$this->board->id}/results")
      ->assertOk()
      ->assertJsonPath('message', 'Results retrieved successfully.')
      ->assertJsonPath('data.board.id', $this->board->id)
      ->assertJsonPath('data.board.number', $this->board->number)
      ->assertJsonPath('data.top', 6)
      ->assertJsonCount(4, 'data.results');

    // best N-S score first
    $this->assertSame([$a->id, $d->id, $c->id, $b->id], $response->json('data.results.*.playing_id'));
    $this->assertSame([620, 620, 170, -100], $response->json('data.results.*.score_ns'));
    $this->assertSame([5, 5, 2, 0], $response->json('data.results.*.matchpoints.ns'));
    $this->assertSame([1, 1, 4, 6], $response->json('data.results.*.matchpoints.ew'));

    $row = $response->json('data.results.0');
    $this->assertSame(Seats::SEATS, array_keys($row['players']));
    $this->assertSame(
      ['id', 'name', 'username', 'description'],
      array_keys($row['players']['N'])
    );
    $this->assertSame($a->seats->firstWhere('seat', 'E')->user_id, $row['players']['E']['id']);
    $this->assertSame('4S', $row['contract']['call']);
    $this->assertSame('N', $row['declarer']);
    $this->assertSame(10, $row['tricks_won']);
    $this->assertSame($a->table_id, $row['table_id']);
  }

  public function test_a_passed_out_board_is_a_result_too(): void
  {
    $played = $this->playing(110);
    $this->playing(0, passedOut: true);

    $this->actingAs($played->seats->first()->user)
      ->getJson("/boards/{$this->board->id}/results")
      ->assertOk()
      ->assertJsonPath('data.top', 2)
      ->assertJsonPath('data.results.1.contract', null)
      ->assertJsonPath('data.results.1.score_ns', 0)
      ->assertJsonPath('data.results.1.matchpoints', ['ns' => 0, 'ew' => 2]);
  }

  public function test_a_user_who_has_not_finished_the_board_gets_403(): void
  {
    $this->playing(620);
    $abandoned = $this->playing(null, finished: false);

    // never sat at it, or left before the end (the playing was detached)
    foreach ([User::factory()->create(), $abandoned->seats->first()->user] as $user) {
      $this->actingAs($user)->getJson("/boards/{$this->board->id}/results")->assertForbidden();
      $this->actingAs($user)->getJson("/boards/{$this->board->id}")->assertForbidden();
    }

    // having finished another board doesn't help
    $other = BoardTable::factory()->finished()->create(['score' => 50]);
    $other->seats()->create(['user_id' => $abandoned->seats->first()->user_id, 'seat' => 'N']);
    $this->actingAs($abandoned->seats->first()->user)
      ->getJson("/boards/{$this->board->id}/results")
      ->assertForbidden();
  }

  public function test_guests_get_401_and_an_unknown_board_404(): void
  {
    $this->getJson("/boards/{$this->board->id}/results")->assertUnauthorized();
    $this->getJson("/boards/{$this->board->id}")->assertUnauthorized();

    $this->actingAs(User::factory()->create())->getJson('/boards/999999/results')->assertNotFound();
  }

  public function test_results_include_tables_that_have_since_been_deleted(): void
  {
    $gone = $this->playing(620);
    $kept = $this->playing(170);

    $gone->table->delete();

    // a player from the deleted table can still read them
    $this->actingAs($gone->seats->first()->user)
      ->getJson("/boards/{$this->board->id}/results")
      ->assertOk()
      ->assertJsonCount(2, 'data.results')
      ->assertJsonPath('data.results.0.playing_id', $gone->id)
      ->assertJsonPath('data.results.0.table_id', null)
      ->assertJsonPath('data.results.0.score_ns', 620)
      ->assertJsonPath('data.results.0.matchpoints.ns', 2)
      ->assertJsonPath('data.results.1.table_id', $kept->table_id);
  }

  public function test_a_board_played_through_the_table_can_be_looked_back_on(): void
  {
    // four players fill a table, are dealt a board and pass it out
    $table = Table::factory()->create(['board_id' => null]);
    $players = [];

    foreach (Seats::SEATS as $seat) {
      $players[$seat] = User::factory()->create();
      app(TableSeatService::class)->seat($table, $players[$seat], $seat);
    }

    $playing = BoardTable::where('table_id', $table->id)->sole();
    $boardId = $playing->board_id;

    $this->actingAs($players['S'])->getJson("/boards/$boardId/results")->assertForbidden();

    $playing->update(['auction_ended_at' => now()]);
    $playing->finish(null);

    $this->actingAs($players['S'])
      ->getJson("/boards/$boardId/results")
      ->assertOk()
      ->assertJsonPath('data.results.0.players.S.id', $players['S']->id);
  }

  public function test_the_board_shows_all_four_hands_to_those_who_finished_it(): void
  {
    $playing = $this->playing(620);

    $deal = $this->actingAs($playing->seats->first()->user)
      ->getJson("/boards/{$this->board->id}")
      ->assertOk()
      ->assertJsonPath('message', 'Board retrieved successfully.')
      ->assertJsonPath('data.id', $this->board->id)
      ->assertJsonPath('data.number', $this->board->number)
      ->assertJsonPath('data.dealer', $this->board->dealer)
      ->assertJsonPath('data.vulnerable', $this->board->vulnerable)
      ->json('data.deal');

    $this->assertSame(Seats::SEATS, array_keys($deal));

    foreach (Seats::SEATS as $seat) {
      $this->assertSame(
        $this->board->cards()->wherePivot('seat', $seat)->pluck('cards.id')->sort()->values()->all(),
        collect($deal[$seat])->pluck('id')->sort()->values()->all()
      );
    }
  }

  public function test_the_history_lists_only_finished_playings_latest_first(): void
  {
    $user = User::factory()->create();

    $older = $this->playing(620, seats: ['E' => $user], finishedAt: now()->subDay());
    $newer = $this->playing(-100, seats: ['S' => $user], board: app(BoardSelectionService::class)->dealBoard());
    $this->playing(null, finished: false, seats: ['N' => $user], board: app(BoardSelectionService::class)->dealBoard());

    // somebody else's
    $this->playing(170);

    $response = $this->actingAs(User::factory()->create())
      ->getJson("/users/$user->id/playings")
      ->assertOk()
      ->assertJsonPath('message', 'Playings retrieved successfully.')
      ->assertJsonPath('data.total', 2)
      ->assertJsonPath('data.current_page', 1);

    $this->assertSame([$newer->id, $older->id], $response->json('data.data.*.playing_id'));

    $row = $response->json('data.data.1');
    $this->assertSame($this->board->number, $row['board']['number']);
    $this->assertSame('E', $row['seat']);
    $this->assertSame($older->seats->firstWhere('seat', 'W')->user_id, $row['partner']['id']);
    $this->assertArrayNotHasKey('email', $row['partner']);
    $this->assertSame('4S', $row['contract']['call']);
    $this->assertSame(620, $row['score_ns']);
    // from the user's side: E-W conceded 620
    $this->assertSame(-620, $row['score']);

    $this->assertSame(-100, $response->json('data.data.0.score'));

    // your own, the same list
    $this->actingAs($user)
      ->getJson('/api/user/playings')
      ->assertOk()
      ->assertJsonPath('data.data', $response->json('data.data'));
  }

  public function test_the_history_survives_the_table_being_deleted(): void
  {
    $playing = $this->playing(620);
    $user = $playing->seats->first()->user;

    $playing->table->delete();

    $this->actingAs($user)
      ->getJson('/api/user/playings')
      ->assertOk()
      ->assertJsonPath('data.total', 1)
      ->assertJsonPath('data.data.0.playing_id', $playing->id)
      ->assertJsonPath('data.data.0.table_id', null)
      ->assertJsonPath('data.data.0.score_ns', 620);
  }

  public function test_the_history_is_paginated(): void
  {
    $user = User::factory()->create();

    for ($i = 0; $i < 21; $i++) {
      $this->playing(50, seats: ['N' => $user], board: app(BoardSelectionService::class)->dealBoard());
    }

    $this->actingAs($user)
      ->getJson('/api/user/playings?page=2')
      ->assertOk()
      ->assertJsonPath('data.total', 21)
      ->assertJsonPath('data.per_page', 20)
      ->assertJsonPath('data.last_page', 2)
      ->assertJsonCount(1, 'data.data');
  }

  public function test_guests_get_401_on_the_history(): void
  {
    $this->getJson('/api/user/playings')->assertUnauthorized();
    $this->getJson('/users/'.User::factory()->create()->id.'/playings')->assertUnauthorized();
  }

  /**
   * A playing of the board at its own table: 4S by North making 10 tricks
   * with the given N-S score (the scores are made up, only compared), or
   * passed out, or unfinished and detached, as a player leaving leaves it.
   *
   * @param  array<string, User>  $seats  players for some seats; the rest are made
   */
  private function playing(
    ?int $scoreNs,
    bool $finished = true,
    bool $passedOut = false,
    array $seats = [],
    ?Board $board = null,
    $finishedAt = null,
  ): BoardTable {
    $contract = ! $finished || $passedOut ? [] : [
      'contract_bid_id' => Bid::where('suit', '4S')->value('id'),
      'declarer_seat' => 'N',
      'tricks_won' => 10,
    ];

    $playing = BoardTable::factory()->create([
      'board_id' => ($board ?? $this->board)->id,
      'table_id' => $finished ? Table::factory()->create(['board_id' => null])->id : null,
      'score' => $finished ? $scoreNs : null,
      'auction_ended_at' => $finished ? now() : null,
      'finished_at' => $finished ? ($finishedAt ?? now()) : null,
      ...$contract,
    ]);

    foreach (Seats::SEATS as $seat) {
      $playing->seats()->create([
        'user_id' => ($seats[$seat] ?? User::factory()->create())->id,
        'seat' => $seat,
      ]);
    }

    return $playing->load('seats.user', 'table');
  }
}

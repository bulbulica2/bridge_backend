<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\PlayingUpdated;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\Table;
use App\Models\User;
use App\Services\PlayingStateService;
use App\Services\ScoringService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ClaimTest extends TestCase
{
  use RefreshDatabase;

  /**
   * North declares 4H, so South is dummy and East leads. North holds every
   * top card.
   */
  private const DEAL = [
    'N' => 'SA SK SQ SJ HA HK HQ DA DK DQ CA CK CQ',
    'E' => 'S10 S9 S8 HJ H10 H9 H8 DJ D10 D9 CJ C10 C9',
    'S' => 'S7 S6 S5 H7 H6 H5 D8 D7 D6 D5 C8 C7 C6',
    'W' => 'S4 S3 S2 H4 H3 H2 D4 D3 D2 C5 C4 C3 C2',
  ];

  private const RANKS = ['J' => 12, 'Q' => 13, 'K' => 14, 'A' => 15];

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

    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = User::factory()->create();
      app(TableSeatService::class)->seat($this->table, $this->players[$seat], $seat);
    }

    $this->table->refresh();
    $this->playing = BoardTable::where('table_id', $this->table->id)->firstOrFail();

    // replace the random deal with a known one
    DB::table('board_card')->where('board_id', $this->table->board_id)->delete();

    foreach (self::DEAL as $seat => $cards) {
      foreach (explode(' ', $cards) as $code) {
        DB::table('board_card')->insert([
          'board_id' => $this->table->board_id,
          'card_id' => $this->card($code)->id,
          'seat' => $seat,
        ]);
      }
    }

    $this->playing->update([
      'contract_bid_id' => Bid::where('suit', '4H')->value('id'),
      'declarer_seat' => 'N',
      'declarer_id' => $this->players['N']->id,
      'auction_ended_at' => now(),
    ]);
  }

  public function test_declarers_claim_accepted_by_both_defenders_finishes_the_board(): void
  {
    // N-S win the first trick: 12 remain
    $this->plays('E S10, N S7, W S4, N SA');

    $this->claim('N', 12)
      ->assertCreated()
      ->assertJsonPath('message', 'Claim made successfully.')
      ->assertJsonPath('data.phase', 'play')
      ->assertJsonPath('data.claim.seat', 'N')
      ->assertJsonPath('data.claim.tricks', 12)
      ->assertJsonPath('data.claim.accepted', []);

    $this->respond('E', true)
      ->assertOk()
      ->assertJsonPath('message', 'Claim accepted.')
      ->assertJsonPath('data.phase', 'play')
      ->assertJsonPath('data.claim.accepted', ['E']);

    $this->respond('W', true)
      ->assertOk()
      ->assertJsonPath('message', 'Claim accepted: the board is finished.')
      ->assertJsonPath('data.phase', 'finished')
      ->assertJsonPath('data.claim', null)
      ->assertJsonPath('data.result.tricks_won', 13)
      ->assertJsonPath('data.result.made_by', 3)
      ->assertJsonPath('data.result.claimed', true);

    $playing = $this->playing->fresh();
    $expected = ScoringService::score(Bid::where('suit', '4H')->sole(), 0, 'N', $playing->board->vulnerable, 13);

    $this->assertSame(13, $playing->tricks_won);
    $this->assertSame($expected, $playing->score);
    $this->assertNotNull($playing->finished_at);
    // an accepted claim is kept
    $this->assertSame(['N', 12, ['E', 'W']], [$playing->claim_seat, $playing->claim_tricks, $playing->claim_accepted]);
  }

  public function test_a_defenders_concession_needs_declarer_and_the_other_defender(): void
  {
    $this->claim('W', 0)->assertCreated();

    // declarer's partner doesn't answer
    $this->respond('S', true)
      ->assertStatus(409)
      ->assertJsonPath('message', "Dummy takes no part in a claim: declarer claims for declarer's side.");

    $this->respond('N', true)->assertOk()->assertJsonPath('data.phase', 'play');
    $this->respond('E', true)->assertOk()->assertJsonPath('data.phase', 'finished');

    $this->assertSame(13, $this->playing->fresh()->tricks_won);
  }

  public function test_a_defenders_claim_leaves_declarer_the_rest(): void
  {
    $this->claim('E', 4)->assertCreated();
    $this->respond('N', true)->assertOk();
    $this->respond('W', true)->assertOk();

    $playing = $this->playing->fresh();
    $this->assertSame(9, $playing->tricks_won);
    $this->assertSame(ScoringService::score(Bid::where('suit', '4H')->sole(), 0, 'N', $playing->board->vulnerable, 9), $playing->score);
  }

  public function test_a_claim_mid_trick_counts_the_open_trick_as_remaining(): void
  {
    // one trick to N-S, then two cards of the second
    $this->plays('E S10, N S7, W S4, N SA, N SK, E S9');

    $this->claim('N', 13)
      ->assertStatus(409)
      ->assertJsonPath('message', 'You can claim between 0 and 12 tricks: 12 remain to be played.');

    $this->claim('N', 12)->assertCreated();
    $this->respond('E', true)->assertOk();
    $this->respond('W', true)->assertOk()->assertJsonPath('data.result.tricks_won', 13);
  }

  public function test_one_reject_clears_the_claim_and_play_goes_on(): void
  {
    $this->claim('N', 13)->assertCreated();
    $this->respond('E', true)->assertOk();

    $this->respond('W', false)
      ->assertOk()
      ->assertJsonPath('message', 'Claim rejected: play goes on.')
      ->assertJsonPath('data.phase', 'play')
      ->assertJsonPath('data.claim', null);

    $playing = $this->playing->fresh();
    $this->assertSame([null, null, null, null], [$playing->claim_seat, $playing->claim_tricks, $playing->claim_accepted, $playing->finished_at]);

    $this->play('E', 'S10')->assertCreated();

    // and a new claim may be made
    $this->claim('W', 0)->assertCreated();
  }

  public function test_the_claimer_may_withdraw_it(): void
  {
    $this->claim('N', 13)->assertCreated();

    $this->actingAs($this->players['E'])
      ->deleteJson("/tables/{$this->table->id}/claim")
      ->assertStatus(409)
      ->assertJsonPath('message', 'Only the claimer (N) may withdraw the claim.');

    $this->actingAs($this->players['N'])
      ->deleteJson("/tables/{$this->table->id}/claim")
      ->assertOk()
      ->assertJsonPath('message', 'Claim withdrawn: play goes on.')
      ->assertJsonPath('data.claim', null);

    $this->assertNull($this->playing->fresh()->claim_seat);

    $this->actingAs($this->players['N'])
      ->deleteJson("/tables/{$this->table->id}/claim")
      ->assertStatus(409)
      ->assertJsonPath('message', 'There is no claim to withdraw.');

    $this->play('E', 'S10')->assertCreated();
  }

  public function test_no_card_and_no_second_claim_while_one_is_pending(): void
  {
    $this->claim('N', 13)->assertCreated();

    $this->play('E', 'S10')
      ->assertStatus(409)
      ->assertJsonPath('message', 'N has claimed: no card may be played until the claim is rejected or withdrawn.');

    $this->claim('E', 0)
      ->assertStatus(409)
      ->assertJsonPath('message', 'A claim is already pending: N claims 13.');

    // the turn doesn't move
    $this->state('E')->assertJsonPath('data.turn', 'E');
  }

  public function test_answers_are_one_per_player_and_not_the_claimers(): void
  {
    $this->respond('E', true)
      ->assertStatus(409)
      ->assertJsonPath('message', 'There is no claim to answer.');

    $this->claim('N', 13)->assertCreated();

    $this->respond('N', true)
      ->assertStatus(409)
      ->assertJsonPath('message', 'You made this claim: withdraw it instead.');

    $this->respond('E', true)->assertOk();

    $this->respond('E', true)
      ->assertStatus(409)
      ->assertJsonPath('message', 'You have already accepted this claim.');
  }

  public function test_dummy_may_not_claim(): void
  {
    $this->claim('S', 13)
      ->assertStatus(409)
      ->assertJsonPath('message', "Dummy takes no part in a claim: declarer claims for declarer's side.");

    $this->assertNull($this->playing->fresh()->claim_seat);
  }

  public function test_no_claim_outside_the_play(): void
  {
    $this->playing->update(['contract_bid_id' => null, 'declarer_seat' => null, 'declarer_id' => null, 'auction_ended_at' => null]);

    $this->claim('N', 13)
      ->assertStatus(409)
      ->assertJsonPath('message', 'The auction is not over yet.');
  }

  public function test_the_claimers_hand_is_public_while_the_claim_is_pending(): void
  {
    $this->plays('E S10, N S7, W S4, N SA');

    $this->claim('N', 12)->assertCreated();

    $north = $this->playing->board->cards()->wherePivot('seat', 'N')->pluck('cards.id')->diff([$this->card('SA')->id]);

    // everyone sees it, dummy included
    foreach (Seats::SEATS as $seat) {
      $shown = collect($this->state($seat)->json('data.claim.hand'))->pluck('id');
      $this->assertEqualsCanonicalizing($north->values()->all(), $shown->all());
    }

    $public = app(PlayingStateService::class)->publicState($this->table);
    $this->assertCount(12, $public['claim']['hand']);
  }

  public function test_every_claim_action_broadcasts(): void
  {
    Event::fake([PlayingUpdated::class]);

    $this->claim('N', 13)->assertCreated();
    $this->respond('E', false)->assertOk();
    $this->claim('N', 13)->assertCreated();
    $this->actingAs($this->players['N'])->deleteJson("/tables/{$this->table->id}/claim")->assertOk();
    $this->claim('E', 0)->assertCreated();
    $this->respond('N', true)->assertOk();

    // a refused action says nothing
    $this->respond('N', true)->assertStatus(409);

    $this->respond('W', true)->assertOk();

    Event::assertDispatchedTimes(PlayingUpdated::class, 7);

    $payloads = collect(Event::dispatched(PlayingUpdated::class))->map(fn ($event) => $event[0]->broadcastWith()['playing']);

    $this->assertSame('N', $payloads[0]['claim']['seat']);
    $this->assertCount(13, $payloads[0]['claim']['hand']);
    $this->assertNull($payloads[1]['claim']);
    $this->assertSame(['N'], $payloads[5]['claim']['accepted']);
    $this->assertSame('finished', $payloads[6]['phase']);
    $this->assertTrue($payloads[6]['result']['claimed']);
  }

  public function test_a_rejected_claim_leaves_no_mark_on_the_result(): void
  {
    $this->claim('N', 13)->assertCreated();
    $this->respond('E', false)->assertOk();

    $this->playing->finish(10);

    $this->state('N')->assertJsonPath('data.result.claimed', false);
  }

  public function test_the_claim_goes_with_a_playing_detached_by_a_player_leaving(): void
  {
    $this->claim('N', 13)->assertCreated();

    $this->actingAs($this->players['E'])->deleteJson("/tables/{$this->table->id}/seats")->assertOk();

    $playing = $this->playing->fresh();
    $this->assertNull($playing->table_id);
    $this->assertNull($playing->finished_at);
  }

  public function test_only_seated_players_may_claim_and_guests_get_401(): void
  {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->postJson("/tables/{$this->table->id}/claim", ['tricks' => 1])->assertForbidden();
    $this->actingAs($stranger)->postJson("/tables/{$this->table->id}/claim/response", ['accept' => true])->assertForbidden();
    $this->actingAs($stranger)->deleteJson("/tables/{$this->table->id}/claim")->assertForbidden();

    $this->app['auth']->forgetGuards();

    $this->postJson("/tables/{$this->table->id}/claim", ['tricks' => 1])->assertUnauthorized();
  }

  public function test_the_request_is_validated(): void
  {
    $this->actingAs($this->players['N'])
      ->postJson("/tables/{$this->table->id}/claim", ['tricks' => 14])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('tricks');

    $this->actingAs($this->players['E'])
      ->postJson("/tables/{$this->table->id}/claim/response", [])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('accept');
  }

  private function claim(string $seat, int $tricks): TestResponse
  {
    return $this->actingAs($this->players[$seat])
      ->postJson("/tables/{$this->table->id}/claim", ['tricks' => $tricks]);
  }

  private function respond(string $seat, bool $accept): TestResponse
  {
    return $this->actingAs($this->players[$seat])
      ->postJson("/tables/{$this->table->id}/claim/response", ['accept' => $accept]);
  }

  private function play(string $seat, string $code): TestResponse
  {
    return $this->actingAs($this->players[$seat])
      ->postJson("/tables/{$this->table->id}/cards", ['card_id' => $this->card($code)->id]);
  }

  /**
   * Play several legal cards in turn, written `'E S10, N S7'` — the seat is
   * the player who acts, so declarer's seat for dummy's cards.
   */
  private function plays(string $plays): void
  {
    foreach (preg_split('/,\s+/', $plays) as $made) {
      [$seat, $code] = explode(' ', $made);
      $this->play($seat, $code)->assertCreated();
    }
  }

  private function state(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->getJson("/tables/{$this->table->id}/playing")->assertOk();
  }

  /**
   * A card from its code: suit, then rank (`SA`, `H10`).
   */
  private function card(string $code): Card
  {
    $rank = self::RANKS[substr($code, 1)] ?? (int) substr($code, 1);

    return Card::where('suit', $code[0])->where('rank', $rank)->firstOrFail();
  }
}

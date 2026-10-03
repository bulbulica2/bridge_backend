<?php

namespace Tests\Feature\Game;

use App\Models\Card;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /cards` is the read-only reference list of the 52 cards, no auth.
 */
class CardListTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed(CardSeeder::class);
  }

  public function test_a_guest_gets_the_52_cards(): void
  {
    $this->getJson('/cards')
      ->assertOk()
      ->assertJsonPath('status', 200)
      ->assertJsonPath('message', 'Cards retrieved successfully.')
      ->assertJsonCount(52, 'data')
      ->assertJsonStructure(['data' => [['id', 'suit', 'rank', 'rank_name']]]);
  }

  public function test_a_guest_gets_one_card_by_id(): void
  {
    $ace = Card::where(['suit' => 'S', 'rank' => 15])->firstOrFail();

    $this->getJson("/cards/$ace->id")
      ->assertOk()
      ->assertJsonPath('data.id', $ace->id)
      ->assertJsonPath('data.suit', 'S')
      ->assertJsonPath('data.rank', 15);
  }
}

<?php

namespace Tests\Unit\Robots;

use App\auxiliary\Seats;
use App\Robots\RobotCardPlayer;
use App\Services\CardPlayService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The robots' card play (`docs/ROBOTS.md`), over in-memory state shaped like
 * `PlayingStateService::stateFor()`: no database. Hands are
 * spades.hearts.diamonds.clubs.
 */
class RobotCardPlayerTest extends TestCase
{
  use MakesCards;

  /**
   * contract, declarer, leader, the leader's hand, the lead
   */
  public static function openingLeads(): array
  {
    return [
      'top of a sequence' => ['H', 'N', 'E', '32.432.KQJ2.5432', 'DK'],
      'fourth best from the longest suit' => ['NT', 'N', 'E', 'K8532.432.Q2.432', 'S3'],
      'never underlead an ace against a suit' => ['H', 'N', 'E', 'A8532.432.Q2.432', 'C4'],
      'underleading an ace is fine in NT' => ['NT', 'N', 'E', 'A8532.432.Q2.432', 'S3'],
      'the ace when every suit is headed by one' => ['H', 'N', 'E', 'A8532.432.A2.A32', 'SA'],
      'low from three to an honour' => ['S', 'N', 'E', '5432.K32.T54.J53', 'H2'],
      'no trump lead while there is another suit' => ['S', 'N', 'E', 'KQJ32.2.5432.432', 'D2'],
    ];
  }

  #[DataProvider('openingLeads')]
  public function test_the_opening_lead(string $strain, string $declarer, string $leader, string $hand, string $lead): void
  {
    $state = $this->state($strain, $declarer, $leader, $this->cards($hand), null);

    $this->assertSame($this->card($lead)['id'], RobotCardPlayer::choose($state));
  }

  /**
   * contract, declarer, the trick so far (lead first), the hand to play
   * from, the robot's own hand when that is dummy's, dummy, the card
   */
  public static function follows(): array
  {
    return [
      'second hand low' => ['NT', 'S', 'S D4', '32.5.AK2.5432', null, '5.4.3.2', 'D2'],
      'third hand high, the cheaper of equals' => ['NT', 'S', 'W D2, N D3', '32.5.KQ5.5432', null, '5.4.3.2', 'DQ'],
      'third hand low under partner\'s winner' => ['NT', 'S', 'W DA, N D3', '32.5.KQ5.5432', null, '5.4.3.2', 'D5'],
      'third hand low when it can\'t beat the card' => ['NT', 'S', 'W D2, N DA', '32.5.KQ5.5432', null, '5.4.3.2', 'D5'],
      'fourth hand wins as cheaply as it can' => ['NT', 'S', 'W DT, N D3, E D4', '32.5.KJ2.5432', null, '5.4.3.2', 'DJ'],
      'fourth hand low under partner\'s winner' => ['NT', 'S', 'W D2, N DA, E D3', '32.5.K5.5432', null, '5.4.3.2', 'D5'],
      'ruff when void and the opponents are winning' => ['H', 'S', 'W SA, N S2, E S3', '-.KQ32.A5432.5432', null, '5.4.3.2', 'H2'],
      'over-ruff' => ['H', 'S', 'N C2, E C3, S H9', '5432.T3.A5432.-', null, '9.8.7.6', 'HT'],
      'discard low from the longest suit when unable to over-ruff' => ['H', 'S', 'N C2, E C3, S HA', '5432.T3.A5432.-', null, '9.8.7.6', 'D2'],
      'discard low under partner\'s winner, sparing a sure winner' => ['H', 'S', 'N C2, E CA, S C3', 'A32.T3.K5.-', null, '9.8.7.6', 'S2'],
      'declarer plays third hand high from dummy' => ['NT', 'S', 'S D2, W D3', '32.5.KQ5.5432', '5.4.3.2', null, 'DQ'],
    ];
  }

  #[DataProvider('follows')]
  public function test_following(
    string $strain,
    string $declarer,
    string $trick,
    string $hand,
    ?string $own,
    ?string $dummy,
    string $card
  ): void {
    $trick = $this->trick($trick);
    $turn = Seats::next(end($trick)['seat']);
    $isDummy = $turn === Seats::partner($declarer);

    // on dummy's turn the robot is declarer: its own cards are `hand`,
    // the ones it plays are dummy's
    $state = $isDummy
      ? $this->state($strain, $declarer, $turn, $this->cards($own), $this->cards($hand), $trick)
      : $this->state($strain, $declarer, $turn, $this->cards($hand), $dummy === null ? null : $this->cards($dummy), $trick);

    $this->assertSame($this->card($card)['id'], RobotCardPlayer::choose($state));
  }

  /**
   * contract, declarer's hand, dummy, the lead
   */
  public static function declarerLeads(): array
  {
    return [
      'draw trumps with a master' => ['H', 'A2.AKQ2.432.5432', 'K3.J43.765.7654', 'HA'],
      'draw trumps low without one' => ['H', 'A2.Q432.432.5432', 'K3.J65.765.7654', 'H2'],
      'cash a sure winner' => ['NT', 'A32.432.5432.432', 'K54.765.876.765', 'SA'],
      'lead towards partner\'s winner' => ['NT', '32.432.5432.5432', 'AK.765.876.7654', 'S2'],
      'ruff in the short hand' => ['H', '5432.-.5432.5432', '-.JT98.9876.9876', 'S2'],
      'set up the long suit' => ['NT', 'Q432.J32.32.J432', '65.654.J8765.765', 'D2'],
    ];
  }

  #[DataProvider('declarerLeads')]
  public function test_declarer_on_lead(string $strain, string $hand, string $dummy, string $lead): void
  {
    // a trick has gone by, so dummy is face up
    $state = $this->state($strain, 'S', 'S', $this->cards($hand), $this->cards($dummy), [], [$this->completeTrick()]);

    $this->assertSame($this->card($lead)['id'], RobotCardPlayer::choose($state));
  }

  public function test_a_defender_cashes_a_sure_winner_after_the_opening_lead(): void
  {
    $state = $this->state('S', 'N', 'E', $this->cards('32.A32.5432.5432'), $this->cards('4.4.4.4'), [], [$this->completeTrick()]);

    $this->assertSame($this->card('HA')['id'], RobotCardPlayer::choose($state));
  }

  /**
   * Four robots play out a few hundred random deals in random contracts:
   * every card is legal by the real rules, and all 13 tricks get played.
   */
  public function test_every_card_is_legal(): void
  {
    for ($board = 0; $board < 200; $board++) {
      $hands = $this->deal();
      $declarer = Seats::SEATS[$board % 4];
      $dummy = Seats::partner($declarer);
      $strain = ['C', 'D', 'H', 'S', 'NT'][mt_rand(0, 4)];
      $trump = $strain === 'NT' ? null : $strain;
      $plays = [];

      for ($count = 0; $count < 52; $count++) {
        $turn = CardPlayService::nextToPlay($plays, $declarer, $trump);
        $acting = CardPlayService::actingSeat($turn, $declarer);

        $tricks = [];

        foreach (array_chunk(array_slice($plays, 0, intdiv($count, 4) * 4), 4) as $trick) {
          $tricks[] = ['cards' => $this->payload($trick)];
        }

        $state = [
          'my_seat' => $acting,
          'turn' => $turn,
          'contract' => ['declarer' => $declarer, 'bid' => ['strain' => $strain]],
          'hand' => $hands[$acting],
          'dummy_hand' => $plays === [] ? null : $hands[$dummy],
          'tricks' => $tricks,
          'current_trick' => $this->payload(CardPlayService::currentTrick($plays)),
        ];

        $id = RobotCardPlayer::choose($state);
        $card = collect($hands[$turn])->firstWhere('id', $id);

        $this->assertNotNull($card, "card $id is not in $turn's hand");
        $this->assertNull(CardPlayService::illegalReason($plays, $hands[$turn], $this->model($card)));

        $plays[] = ['seat' => $turn, 'card' => $this->model($card)];
        $hands[$turn] = array_values(array_filter($hands[$turn], fn ($held) => $held['id'] !== $id));
      }

      $this->assertNull(CardPlayService::nextToPlay($plays, $declarer, $trump));
    }
  }

  /**
   * @param  list<array<string, mixed>>  $trick
   * @param  list<array<string, mixed>>  $tricks
   * @return array<string, mixed>
   */
  private function state(
    string $strain,
    string $declarer,
    string $turn,
    array $hand,
    ?array $dummy,
    array $trick = [],
    array $tricks = []
  ): array {
    return [
      'my_seat' => CardPlayService::actingSeat($turn, $declarer),
      'turn' => $turn,
      'contract' => ['declarer' => $declarer, 'bid' => ['strain' => $strain]],
      'hand' => $hand,
      'dummy_hand' => $dummy,
      'tricks' => $tricks,
      'current_trick' => $trick,
    ];
  }

  /**
   * A trick written `'W D2, N D3'`, lead first, in the payload shape.
   *
   * @return list<array{seat: string, card: array{id: int, suit: string, rank: int}}>
   */
  private function trick(string $trick): array
  {
    return array_map(function ($play) {
      [$seat, $card] = explode(' ', $play);

      return ['seat' => $seat, 'card' => $this->card($card)];
    }, explode(', ', $trick));
  }

  /**
   * A finished trick of cards no hand in these tests holds.
   *
   * @return array{cards: list<array<string, mixed>>}
   */
  private function completeTrick(): array
  {
    return ['cards' => []];
  }

  /**
   * @param  list<array{seat: string, card: \App\Models\Card}>  $plays
   * @return list<array{seat: string, card: array{id: int, suit: string, rank: int}}>
   */
  private function payload(array $plays): array
  {
    return array_map(fn ($play) => [
      'seat' => $play['seat'],
      'card' => ['id' => (int) $play['card']->id, 'suit' => $play['card']->suit, 'rank' => (int) $play['card']->rank],
    ], $plays);
  }
}

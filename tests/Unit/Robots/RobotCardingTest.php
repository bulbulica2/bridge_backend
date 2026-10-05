<?php

namespace Tests\Unit\Robots;

use App\Models\BoardMessage;
use App\Robots\RobotCarding;
use PHPUnit\Framework\TestCase;

/**
 * What a robot defender's card means, by the kind of card: N declares, so
 * E and W defend and S is dummy.
 */
class RobotCardingTest extends TestCase
{
  public function test_declarers_and_dummys_cards_carry_no_agreement(): void
  {
    $plays = self::plays('E D, S D, W D, N D');

    $this->assertNull(RobotCarding::explain($plays, 1, 'N', 'S'));
    $this->assertNull(RobotCarding::explain($plays, 3, 'N', 'S'));
  }

  public function test_leads(): void
  {
    $plays = self::plays('E D, S D, W D, N D, W H');

    $this->assertSame(RobotCarding::OPENING_LEAD, RobotCarding::explain($plays, 0, 'N', null));
    $this->assertSame(RobotCarding::LATER_LEAD, RobotCarding::explain($plays, 4, 'N', null));
  }

  public function test_following_partners_lead_is_attitude(): void
  {
    $this->assertSame(RobotCarding::ATTITUDE, RobotCarding::explain(self::plays('E D, S D, W D'), 2, 'N', null));
  }

  public function test_following_declarers_lead_is_count_the_first_time_only(): void
  {
    // N leads clubs, then (after E's diamond trick) clubs again
    $plays = self::plays('N C, E C, S C, W C, E D, S D, W D, N D, N C, E C');

    $this->assertSame(RobotCarding::COUNT, RobotCarding::explain($plays, 1, 'N', null));
    $this->assertSame(RobotCarding::COUNT, RobotCarding::explain($plays, 3, 'N', null));
    $this->assertSame(RobotCarding::NO_COUNT, RobotCarding::explain($plays, 9, 'N', null));
  }

  public function test_not_following_is_a_ruff_or_a_discard(): void
  {
    $plays = self::plays('N C, E S, S C, W H');

    $this->assertSame(RobotCarding::RUFF, RobotCarding::explain($plays, 1, 'N', 'S'));
    $this->assertSame(RobotCarding::DISCARD, RobotCarding::explain($plays, 3, 'N', 'S'));
    $this->assertSame(RobotCarding::DISCARD, RobotCarding::explain($plays, 1, 'N', null));
  }

  public function test_every_answer_fits_in_a_message(): void
  {
    foreach ([RobotCarding::OPENING_LEAD, RobotCarding::LATER_LEAD, RobotCarding::ATTITUDE, RobotCarding::COUNT, RobotCarding::NO_COUNT, RobotCarding::RUFF, RobotCarding::DISCARD] as $answer) {
      $this->assertLessThanOrEqual(BoardMessage::BODY_MAX, mb_strlen($answer));
    }
  }

  /**
   * Plays written `'E D, S D'`: a seat and the card's suit.
   *
   * @return list<array{seat: string, suit: string}>
   */
  private static function plays(string $plays): array
  {
    return array_map(function ($play) {
      [$seat, $suit] = explode(' ', $play);

      return ['seat' => $seat, 'suit' => $suit];
    }, explode(', ', $plays));
  }
}

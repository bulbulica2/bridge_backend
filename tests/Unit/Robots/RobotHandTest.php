<?php

namespace Tests\Unit\Robots;

use App\Robots\RobotHand;
use PHPUnit\Framework\TestCase;

class RobotHandTest extends TestCase
{
  use MakesCards;

  public function test_high_card_points(): void
  {
    $this->assertSame(0, $this->hand('T98.T98.T987.T98')->hcp());
    $this->assertSame(10, $this->hand('AKQJ.432.432.432')->hcp());
    $this->assertSame(40, $this->hand('AKQJ.AKQJ.AKQ.AK')->hcp() + $this->hand('-.-.J.QJ')->hcp());
  }

  public function test_suit_lengths_and_cards_high_to_low(): void
  {
    $hand = $this->hand('2AK.QT3.-.J98765');

    $this->assertSame(3, $hand->length('S'));
    $this->assertSame(0, $hand->length('D'));
    $this->assertSame(6, $hand->length('C'));
    $this->assertSame([15, 14, 2], array_column($hand->cards('S'), 'rank'));
    $this->assertCount(12, $hand->all());
    $this->assertTrue($hand->holds('H', 10));
    $this->assertFalse($hand->holds('H', 12));
  }

  public function test_balanced_shapes(): void
  {
    $this->assertTrue($this->hand('AK2.Q32.J32.5432')->isBalanced());  // 3-3-3-4
    $this->assertTrue($this->hand('AK32.Q32.J2.5432')->isBalanced());  // 4-3-2-4
    $this->assertTrue($this->hand('AK432.Q32.J2.542')->isBalanced());  // 5-3-2-3
    $this->assertFalse($this->hand('AK432.Q2.J2.5432')->isBalanced()); // two doubletons
    $this->assertFalse($this->hand('AK432.Q.J32.5432')->isBalanced()); // a singleton
    $this->assertFalse($this->hand('AK4325.Q32.J32.5')->isBalanced());

    $this->assertTrue($this->hand('AK432.Q2.J2.5432')->isSemiBalanced());  // 5-2-2-4
    $this->assertTrue($this->hand('AK2.Q2.J2.765432')->isSemiBalanced());  // 3-2-2-6
    $this->assertFalse($this->hand('AK432.Q.J32.5432')->isSemiBalanced()); // a singleton
  }

  public function test_stoppers(): void
  {
    $hand = $this->hand('A.K2.Q32.J432');

    foreach (['S', 'H', 'D', 'C'] as $suit) {
      $this->assertTrue($hand->hasStopper($suit), $suit);
    }

    $this->assertFalse($this->hand('K.Q2.J32.5432')->hasStopper('S'));
    $this->assertFalse($this->hand('K.Q2.J32.5432')->hasStopper('H'));
    $this->assertFalse($this->hand('K.Q2.J32.5432')->hasStopper('D'));
    $this->assertFalse($this->hand('K.Q2.J32.5432')->hasStopper('C'));
  }

  public function test_aces_honours_and_suit_quality(): void
  {
    $hand = $this->hand('AKJ932.QT4.A2.32');

    $this->assertSame(2, $hand->aces());
    $this->assertSame(2, $hand->honours('S'));
    $this->assertSame(3, $hand->honours('S', 5));
    $this->assertTrue($hand->isGoodSuit('S'));        // two of the top three
    $this->assertTrue($this->hand('QJT932.-.-.-')->isGoodSuit('S')); // three of the top five
    $this->assertFalse($this->hand('KJ9876.-.-.-')->isGoodSuit('S'));
    $this->assertTrue($this->hand('AQT876.-.-.-')->isVeryGoodSuit('S'));   // A-Q-10
    $this->assertFalse($this->hand('AQ9876.-.-.-')->isVeryGoodSuit('S'));  // good, not very good
    $this->assertFalse($this->hand('QJT876.-.-.-')->isVeryGoodSuit('S'));  // one of the top three
    $this->assertTrue($hand->stops(['S', 'D']));
    $this->assertFalse($hand->stops(['S', 'C']));
  }

  public function test_losers_and_quick_tricks(): void
  {
    // board 10's East: the singleton queen is a loser, so 7 losers
    $east = $this->hand('Q.AT76532.65.A64');

    $this->assertSame(7.0, $east->losers());
    $this->assertSame(2.0, $east->quickTricks());

    // Q-J-x is a winner and a half better than Q-x-x; Q-x and a bare Q are not
    $this->assertSame(2.0, $this->hand('QJT2.-.-.-')->losers());
    $this->assertSame(2.5, $this->hand('Q432.-.-.-')->losers());
    $this->assertSame(1.0, $this->hand('AQ2.-.-.-')->losers());
    $this->assertSame(2.0, $this->hand('Q2.-.-.-')->losers());
    $this->assertSame(1.0, $this->hand('K2.-.-.-')->losers());
    $this->assertSame(0.0, $this->hand('A.-.-.-')->losers());
    $this->assertSame(0.0, $this->hand('-.-.-.-')->losers());

    $this->assertSame(1.5 + 1.0 + 0.5 + 2.0, $this->hand('AQ2.KQ2.K2.AK2')->quickTricks());
    $this->assertSame(0.0, $this->hand('K.QJ2.-.-')->quickTricks());
  }

  public function test_longest_prefers_the_higher_suit_on_a_tie(): void
  {
    $hand = $this->hand('AK432.Q5432.2.32');

    $this->assertSame('S', $hand->longest(RobotHand::SUITS));
    $this->assertSame('H', $hand->longest(['H', 'D', 'C']));
    $this->assertNull($hand->longest(RobotHand::MINORS, 3));
  }
}

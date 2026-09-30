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

  public function test_longest_prefers_the_higher_suit_on_a_tie(): void
  {
    $hand = $this->hand('AK432.Q5432.2.32');

    $this->assertSame('S', $hand->longest(RobotHand::SUITS));
    $this->assertSame('H', $hand->longest(['H', 'D', 'C']));
    $this->assertNull($hand->longest(RobotHand::MINORS, 3));
  }
}

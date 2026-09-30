<?php

namespace App\Robots;

/**
 * A robot's card (`docs/ROBOTS.md` lists the rules): declarer plans and
 * plays by `DeclarerPlay`, a defender by `DefenderPlay`, both throwing
 * cards by `Discards`, and the last few tricks are searched by `Endgame`.
 *
 * Pure: it reads the game state exactly as the robot's seat is served it
 * (`PlayingStateService::stateFor()`), so it sees its own hand, dummy once
 * it is face up, and the cards played, never another hand. When it is
 * dummy's turn the robot is declarer and plays from `dummy_hand`. Whatever
 * it picks is legal: it follows suit whenever it can.
 */
class RobotCardPlayer
{
  /**
   * The id of the card to play for `turn`.
   *
   * @param  array<string, mixed>  $state  `stateFor()` of the seat acting for `turn`
   */
  public static function choose(array $state): int
  {
    $view = PlayView::fromState($state);
    $card = self::byRules($view);

    return (Endgame::choose($view, $card) ?? $card)['id'];
  }

  /**
   * @return array{id: int, suit: string, rank: int}
   */
  public static function byRules(PlayView $view): array
  {
    return match (true) {
      $view->trick !== [] && $view->isDeclarerSide() => DeclarerPlay::follow($view),
      $view->trick !== [] => DefenderPlay::follow($view),
      $view->isDeclarerSide() => DeclarerPlay::lead($view),
      default => DefenderPlay::lead($view),
    };
  }
}

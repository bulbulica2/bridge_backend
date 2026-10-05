<?php

namespace App\Solvers;

use App\Models\Card;

/**
 * A double dummy solver: how many tricks declarer takes with all four hands
 * in view and both sides playing their best. `DdsSolver` is the real one;
 * it is bound only when `bridge.dds_library` names the DDS library, and
 * `DoubleDummyService` runs it from the queue, never in a request.
 */
interface DoubleDummySolver
{
  /**
   * The double dummy table: declarer's tricks for each declarer and strain.
   *
   * @param  array<string, iterable<Card>>  $deal  each seat's 13 cards, by seat (N, E, S, W)
   * @return array<string, array<string, int>> seat => strain (C, D, H, S, NT) => tricks
   */
  public function table(array $deal): array;

  /**
   * Declarer's tricks after each card the opening leader (declarer's left)
   * could lead, best play on both sides from there on.
   *
   * @param  array<string, iterable<Card>>  $deal  each seat's 13 cards, by seat (N, E, S, W)
   * @param  string  $declarer  N, E, S or W
   * @param  string  $strain  C, D, H, S or NT
   * @return array<int, int> card id => declarer's tricks, one per card in the leader's hand
   */
  public function leads(array $deal, string $declarer, string $strain): array;
}

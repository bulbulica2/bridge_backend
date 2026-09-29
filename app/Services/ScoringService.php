<?php

namespace App\Services;

use App\auxiliary\Vulnerability;
use App\Models\Bid;
use InvalidArgumentException;

/**
 * Duplicate scoring (`GAME-RULES.md` §6): what a played contract is worth.
 *
 * Pure functions with no database, like the auction and play rules, so they
 * are unit-tested on their own (`tests/Unit/ScoringTest`). Scores are from
 * declarer's side; `BoardTable::finish()` turns them round for N-S.
 */
class ScoringService
{
  /**
   * The book: the first six tricks, which the contract's level is counted on
   * top of.
   */
  public const BOOK = 6;

  /**
   * Matchpoints (§6, "Comparing results"): each result on a board against
   * every other one, 2 for a better score and 1 for a tie. Takes the N-S
   * scores and returns N-S's matchpoints in the same order; E-W's are
   * `matchpointTop()` minus N-S's.
   *
   * Example: 620, 620, 170, −100 → 5, 5, 2, 0 out of a top of 6.
   *
   * @param  list<int>  $scoresNs
   * @return list<int>
   */
  public static function matchpoints(array $scoresNs): array
  {
    return array_map(function (int $score) use ($scoresNs) {
      $points = 0;

      foreach ($scoresNs as $other) {
        $points += match (true) {
          $score > $other => 2,
          $score === $other => 1,
          default => 0,
        };
      }

      // the loop compared the result with itself too: a tie
      return $points - 1;
    }, array_values($scoresNs));
  }

  /**
   * The most matchpoints a result can get on a board played `$results` times.
   */
  public static function matchpointTop(int $results): int
  {
    return max(0, 2 * ($results - 1));
  }

  /**
   * The score of a contract for declarer's side: positive when made,
   * negative (the defenders' score) when defeated.
   *
   * @param  int  $doubled  0, 1 (doubled) or 2 (redoubled)
   * @param  string  $vulnerable  the board's `Vulnerability` value
   * @param  int  $tricksWon  tricks taken by declarer's side, 0–13
   */
  public static function score(Bid $contract, int $doubled, string $declarerSeat, string $vulnerable, int $tricksWon): int
  {
    if (! $contract->isContract()) {
      throw new InvalidArgumentException("Call '{$contract->suit}' is not a contract.");
    }

    if ($doubled < 0 || $doubled > 2) {
      throw new InvalidArgumentException("doubled must be 0, 1 or 2, got $doubled.");
    }

    if ($tricksWon < 0 || $tricksWon > CardPlayService::TRICKS) {
      throw new InvalidArgumentException("tricksWon must be 0–13, got $tricksWon.");
    }

    $vul = self::isVulnerable($declarerSeat, $vulnerable);
    $needed = $contract->level + self::BOOK;

    if ($tricksWon < $needed) {
      return -self::undertricks($needed - $tricksWon, $doubled, $vul);
    }

    $trickPoints = self::trickPoints($contract->level, $contract->strain) * (2 ** $doubled);

    $score = $trickPoints;
    $score += $trickPoints >= 100 ? ($vul ? 500 : 300) : 50;
    $score += match ($contract->level) {
      6 => $vul ? 750 : 500,
      7 => $vul ? 1500 : 1000,
      default => 0,
    };
    // the "insult" for making a doubled or redoubled contract
    $score += 50 * $doubled;

    $overtrick = $doubled === 0 ? self::trickValue($contract->strain) : ($vul ? 200 : 100) * $doubled;

    return $score + ($tricksWon - $needed) * $overtrick;
  }

  /**
   * Whether the side `$seat` plays for is vulnerable on a board.
   */
  public static function isVulnerable(string $seat, string $vulnerable): bool
  {
    return $vulnerable === Vulnerability::BOTH
      || $vulnerable === (CardPlayService::side($seat) === 'ns' ? Vulnerability::NS : Vulnerability::EW);
  }

  /**
   * Trick points for the bid tricks, undoubled: 20 a trick in a minor, 30 in
   * a major, and in NT 40 for the first and 30 after.
   */
  private static function trickPoints(int $level, string $strain): int
  {
    return $level * self::trickValue($strain) + ($strain === 'NT' ? 10 : 0);
  }

  /**
   * An undoubled trick's worth (and an undoubled overtrick's): 20 in a
   * minor, 30 in a major or NT.
   */
  private static function trickValue(string $strain): int
  {
    return in_array($strain, ['C', 'D'], true) ? 20 : 30;
  }

  /**
   * What the defenders get for `$down` undertricks. Undoubled, every one is
   * worth the same; doubled, the 1st, the 2nd–3rd and the 4th onwards each
   * have their own value; redoubled is twice doubled.
   */
  private static function undertricks(int $down, int $doubled, bool $vul): int
  {
    if ($doubled === 0) {
      return $down * ($vul ? 100 : 50);
    }

    $total = 0;

    for ($i = 1; $i <= $down; $i++) {
      $total += match (true) {
        $i === 1 => $vul ? 200 : 100,
        $i <= 3 => $vul ? 300 : 200,
        default => 300,
      };
    }

    return $total * $doubled;
  }
}

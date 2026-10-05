<?php

namespace App\Solvers;

use App\auxiliary\Seats;
use App\auxiliary\Suits;
use App\Models\Card;
use FFI;
use FFI\CData;
use RuntimeException;

/**
 * Bo Haglund & Søren Hein's DDS (https://github.com/dds-bridge/dds), the
 * standard double dummy solver, called through PHP's FFI extension. It
 * binds DDS's legacy C API (`CalcDDtablePBN`, `SolveBoardPBN`), which both
 * 2.9 (Debian/Ubuntu's `libdds0`) and 3.x export with the same structs.
 *
 * FFI rather than a CLI: DDS ships no command line tool that prints these,
 * so a CLI would be one more native program for us to write and build,
 * while FFI binds the library as it comes. The library is loaded once per
 * worker and kept; and since only `queue:work` solves (the CLI, where FFI
 * works under the default `ffi.enable=preload`), the web server never
 * needs FFI. Loading it, the solver caps DDS's memory and threads
 * (`SetResources`, `bridge.dds_memory_mb`/`dds_threads`): left to itself,
 * DDS sizes its tables for every core of the machine.
 *
 * DDS numbers hands N=0, E=1, S=2, W=3 (our `Seats::SEATS` order) and
 * strains S=0, H=1, D=2, C=3, NT=4; ranks run 2…14 (A=14), where our
 * `cards.rank` skips 11.
 */
class DdsSolver implements DoubleDummySolver
{
  private const HEADER = <<<'C'
    struct ddTableDealPBN { char cards[80]; };
    struct ddTableResults { int resTable[5][4]; };
    struct dealPBN {
      int trump;
      int first;
      int currentTrickSuit[3];
      int currentTrickRank[3];
      char remainCards[80];
    };
    struct futureTricks {
      int nodes;
      int cards;
      int suit[13];
      int rank[13];
      int equals[13];
      int score[13];
    };
    int CalcDDtablePBN(struct ddTableDealPBN tableDealPBN, struct ddTableResults *tablep);
    int SolveBoardPBN(struct dealPBN dl, int target, int solutions, int mode, struct futureTricks *futp, int threadIndex);
    void SetResources(int maxMemoryMB, int maxThreads);
    C;

  /** DDS's strain order: the four suits as it numbers them, then NT. */
  private const STRAINS = ['S', 'H', 'D', 'C', 'NT'];

  /** DDS's code for a call that succeeded. */
  private const RETURN_NO_FAULT = 1;

  private const RANK_CHARS = [10 => 'T', 12 => 'J', 13 => 'Q', 14 => 'K', 15 => 'A'];

  /**
   * One FFI instance per library for the whole process, never freed. FFI
   * caches the type it parses for a literal like `'struct dealPBN'` against
   * the instance that parsed it: once that instance is freed, a new one
   * allocated at the same address reuses the dangling type, and a field
   * resolves to another struct's (CI saw `trump` turn into an `int[13]`).
   *
   * @var array<string, FFI>
   */
  private static array $libraries = [];

  /**
   * @param  string  $library  path to `libdds.so` / `dds.dll` (`bridge.dds_library`)
   * @param  int  $memoryMb  the most memory DDS may take (`bridge.dds_memory_mb`, 0: DDS's choice)
   * @param  int  $threads  the most threads DDS may run (`bridge.dds_threads`, 0: DDS's choice)
   */
  public function __construct(private string $library, private int $memoryMb = 0, private int $threads = 0) {}

  public function table(array $deal): array
  {
    $ffi = $this->ffi();

    $tableDeal = $ffi->new('struct ddTableDealPBN');
    self::write($tableDeal->cards, self::pbn($deal));

    $results = $ffi->new('struct ddTableResults');
    self::check($ffi->CalcDDtablePBN($tableDeal, FFI::addr($results)), 'CalcDDtablePBN');

    $table = [];

    foreach (Seats::SEATS as $hand => $seat) {
      foreach (array_keys(Suits::ALL_SUIT_NAMES) as $strain) {
        $table[$seat][$strain] = $results->resTable[self::strain($strain)][$hand];
      }
    }

    return $table;
  }

  public function leads(array $deal, string $declarer, string $strain): array
  {
    $ffi = $this->ffi();
    $leader = Seats::next($declarer);

    // the opening lead: nothing played to the trick yet (FFI zeroes it)
    $position = $ffi->new('struct dealPBN');
    $position->trump = self::strain($strain);
    $position->first = array_search($leader, Seats::SEATS, true);
    self::write($position->remainCards, self::pbn($deal));

    // target -1, solutions 3: every card the leader may play, each with the
    // most tricks the leader's side then takes; mode 1: search even when
    // there is one card to play
    $future = $ffi->new('struct futureTricks');
    self::check($ffi->SolveBoardPBN($position, -1, 3, 1, FFI::addr($future), 0), 'SolveBoardPBN');

    // DDS returns one card per run of equivalent cards: the others are
    // the bits of `equals`, rank r as 1 << r
    $tricks = [];

    for ($i = 0; $i < $future->cards; $i++) {
      $suit = self::STRAINS[$future->suit[$i]];
      $declarerTricks = 13 - $future->score[$i];
      $tricks[$suit.$future->rank[$i]] = $declarerTricks;

      for ($rank = 2; $rank <= 14; $rank++) {
        if ($future->equals[$i] & (1 << $rank)) {
          $tricks[$suit.$rank] = $declarerTricks;
        }
      }
    }

    $leads = [];

    foreach ($deal[$leader] as $card) {
      $leads[(int) $card->id] = $tricks[$card->suit.self::rank($card)];
    }

    return $leads;
  }

  /**
   * The deal in PBN, as DDS reads it: `N:` then the four hands clockwise
   * from North, each as its spades, hearts, diamonds and clubs (high to
   * low) separated by dots.
   *
   * @param  array<string, iterable<Card>>  $deal
   */
  public static function pbn(array $deal): string
  {
    $hands = [];

    foreach (Seats::SEATS as $seat) {
      $suits = [];

      foreach (array_slice(self::STRAINS, 0, 4) as $suit) {
        $ranks = collect($deal[$seat])->where('suit', $suit)->pluck('rank')->map(fn ($rank) => (int) $rank)->sortDesc();
        $suits[] = $ranks->map(fn ($rank) => self::RANK_CHARS[$rank] ?? (string) $rank)->implode('');
      }

      $hands[] = implode('.', $suits);
    }

    return 'N:'.implode(' ', $hands);
  }

  /**
   * A PBN deal as the solver takes it, the other way round from `pbn()`:
   * each seat's cards as `Card` models, not saved, with ids of their own
   * (unique within the deal, not `cards.id`). The first hand is the seat
   * before the colon, the others follow clockwise.
   *
   * @return array<string, list<Card>>
   */
  public static function deal(string $pbn): array
  {
    [$seat, $hands] = explode(':', $pbn);
    $ranks = array_flip(self::RANK_CHARS);
    $deal = [];

    foreach (explode(' ', $hands) as $hand) {
      $deal[$seat] = [];

      foreach (array_combine(array_slice(self::STRAINS, 0, 4), explode('.', $hand)) as $suit => $chars) {
        // a void is an empty suit, which str_split() makes no cards of
        foreach (str_split($chars) as $char) {
          $card = new Card(['suit' => $suit, 'rank' => $ranks[$char] ?? (int) $char]);
          $card->id = self::strain($suit) * 16 + $card->rank;
          $deal[$seat][] = $card;
        }
      }

      $seat = Seats::next($seat);
    }

    return $deal;
  }

  /**
   * A card as PBN writes it: its suit, then its rank (`SQ`, `HT`, `C2`).
   */
  public static function cardName(Card $card): string
  {
    return $card->suit.(self::RANK_CHARS[$card->rank] ?? (string) $card->rank);
  }

  /**
   * The library, bound the first time and its resources set then, once.
   *
   * @return FFI
   */
  private function ffi(): object
  {
    if (! isset(self::$libraries[$this->library])) {
      $ffi = $this->load();
      $ffi->SetResources($this->memoryMb, $this->threads);
      self::$libraries[$this->library] = $ffi;
    }

    return self::$libraries[$this->library];
  }

  /**
   * Bind the library. Overridden only by tests, which bind a stand-in.
   *
   * @return FFI
   */
  protected function load(): object
  {
    if (! extension_loaded('ffi')) {
      throw new RuntimeException('DDS_LIBRARY is set, but PHP\'s FFI extension is not loaded: enable extension=ffi in php.ini.');
    }

    return FFI::cdef(self::HEADER, $this->library);
  }

  /**
   * Copy a string into a C char array, which FFI has zeroed, so it stays
   * NUL-terminated.
   */
  private static function write(CData $chars, string $text): void
  {
    FFI::memcpy($chars, $text, strlen($text));
  }

  private static function check(int $code, string $function): void
  {
    if ($code !== self::RETURN_NO_FAULT) {
      throw new RuntimeException("DDS $function failed with code $code.");
    }
  }

  private static function strain(string $strain): int
  {
    return array_search($strain, self::STRAINS, true);
  }

  /**
   * A card's rank as DDS numbers it: J=11 … A=14, where `cards.rank` has
   * J=12 … A=15.
   */
  private static function rank(Card $card): int
  {
    return $card->rank > 10 ? $card->rank - 1 : (int) $card->rank;
  }
}

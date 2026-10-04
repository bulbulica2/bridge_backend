<?php

namespace App\Robots;

use App\auxiliary\Suits;
use Closure;

/**
 * The robots' bidding system, SAYC-style (`docs/ROBOTS.md` lists every
 * rule): for the seat about to call, the ordered list of calls it may make,
 * each with its meaning and the hands that make it.
 *
 * It is the one place the system lives. A robot makes the first legal call
 * its hand fits (`RobotBidder::bid()`), and any seat's call — a robot's or
 * a human's — is read back as the meaning of the rules that make it in that
 * position (`RobotBidder::read()`), so what a robot means and what its
 * partner understands can't drift apart.
 *
 * A conventional rule says so on its meaning (`alert: true`): a robot
 * alerts that call to the opponents with its explanation. Natural calls
 * aren't alerted.
 *
 * Points are high-card points only.
 */
class BiddingSystem
{
  /**
   * Combined high-card points for game, a small slam and a grand slam.
   */
  public const GAME = 25;

  public const SLAM = 33;

  public const GRAND = 37;

  private const STRAINS = ['S', 'H', 'D', 'C', 'NT'];

  /**
   * @var list<BidRule>
   */
  private array $rules = [];

  private function __construct(private readonly AuctionView $v) {}

  /**
   * The calls the seat in `$view` may make, in the order it tries them.
   *
   * @return list<BidRule>
   */
  public static function rules(AuctionView $view): array
  {
    $system = new self($view);
    $system->situation();

    return $system->rules;
  }

  /**
   * Where the auction stands for this seat, which picks the rules.
   */
  private function situation(): void
  {
    $v = $this->v;

    if ($v->opening() === null) {
      $this->opening();

      return;
    }

    $partner = $v->partner();
    $partnersLast = $v->lastAction($partner);
    $ask = $partnersLast === null ? null : $v->meaning($partnersLast)->asks;

    // partner asked a question just now, and the opponent passed or doubled
    if ($ask !== null && $partnersLast === count($v->calls) - 2
      && in_array($v->lastCall(), [AuctionView::PASS, AuctionView::DOUBLE], true)) {
      $this->answer($ask);

      if ($this->rules !== []) {
        return;
      }
    }

    if ($v->lastAction($v->seat) === null) {
      match (true) {
        $partnersLast === null => $this->overcall(),
        $v->seatOf($v->opening()) === $partner => $this->response(),
        default => $this->advance(),
      };

      return;
    }

    if ($this->isOpenersRebid()) {
      $this->openersRebid();

      return;
    }

    $this->placement();
  }

  /**
   * @param  Closure(RobotHand): bool  $when
   */
  private function add(?string $call, BidMeaning $meaning, Closure $when): void
  {
    if ($call !== null) {
      $this->rules[] = new BidRule($call, $meaning, $when);
    }
  }

  // ---------------------------------------------------------------- opening

  /**
   * Nobody has bid yet.
   */
  private function opening(): void
  {
    $fourthSeat = count($this->v->calls) === 3;

    $this->add('2C', new BidMeaning('Strong 2♣', 22, note: 'artificial', force: BidMeaning::ROUND, asks: 'waiting', tag: 'strong', alert: true),
      fn (RobotHand $h) => $h->hcp() >= 22);
    $this->add('2NT', new BidMeaning('Opening', 20, 21, balanced: true, asks: 'nt', suit: 'NT', tag: 'nt'),
      fn (RobotHand $h) => $h->isBalanced() && $this->between($h, 20, 21));
    $this->add('1NT', new BidMeaning('Opening', 15, 17, balanced: true, asks: 'nt', suit: 'NT', tag: 'nt'),
      fn (RobotHand $h) => $h->isBalanced() && $this->between($h, 15, 17));

    foreach (RobotHand::MAJORS as $major) {
      $this->add("1$major", new BidMeaning('Opening', 12, 21, [$major => 5], suit: $major, tag: 'one'),
        fn (RobotHand $h) => $h->hcp() >= 12 && $h->longest(RobotHand::MAJORS, 5) === $major);
    }

    // the longer minor: diamonds with 4-4, clubs with 3-3
    $this->add('1D', new BidMeaning('Opening', 12, 21, ['D' => 3], suit: 'D', tag: 'one'),
      fn (RobotHand $h) => $h->hcp() >= 12
        && ($h->length('D') > $h->length('C') || ($h->length('D') === $h->length('C') && $h->length('D') >= 4)));
    $this->add('1C', new BidMeaning('Opening', 12, 21, ['C' => 3], suit: 'C', tag: 'one'),
      fn (RobotHand $h) => $h->hcp() >= 12);

    // nobody preempts in fourth seat: they would rather pass the board out
    if (! $fourthSeat) {
      foreach (['S', 'H', 'D'] as $suit) {
        $this->add("2$suit", new BidMeaning('Weak two', 5, 11, [$suit => 6], suit: $suit, tag: 'weak'),
          fn (RobotHand $h) => $this->between($h, 5, 11) && $h->length($suit) === 6 && $h->isGoodSuit($suit)
            && array_filter(RobotHand::MAJORS, fn ($major) => $major !== $suit && $h->length($major) >= 4) === []);
      }

      foreach (RobotHand::MAJORS as $major) {
        $this->add("4$major", new BidMeaning('Preempt', 5, 10, [$major => 8], suit: $major, tag: 'preempt'),
          fn (RobotHand $h) => $this->between($h, 5, 10) && $h->length($major) >= 8 && $h->isGoodSuit($major));
      }

      foreach (RobotHand::SUITS as $suit) {
        $this->add("3$suit", new BidMeaning('Preempt', 5, 10, [$suit => 7], suit: $suit, tag: 'preempt'),
          fn (RobotHand $h) => $this->between($h, 5, 10) && $h->length($suit) >= 7 && $h->isGoodSuit($suit));
      }
    }

    $this->add(AuctionView::PASS, new BidMeaning('Pass', 0, 11), fn () => true);
  }

  // ---------------------------------------------------------------- answers

  /**
   * Partner's last call asked a question (`BidMeaning::$asks`).
   */
  private function answer(string $ask): void
  {
    [$kind, $param] = array_pad(explode(':', $ask, 2), 2, null);

    match ($kind) {
      'waiting' => $this->add('2D', new BidMeaning('Waiting', note: 'artificial, any strength', asks: 'strong-rebid', alert: true), fn () => true),
      'strong-rebid' => $this->strongRebid(),
      'strong-suit' => $this->afterStrongSuit(),
      'nt' => $this->ntResponse(),
      'stayman' => $this->staymanAnswer(),
      'after-stayman' => $this->afterStayman(),
      'transfer' => $this->transferAnswer($param),
      'after-transfer' => $this->afterTransfer(),
      'choose' => $this->chooseGame($param),
      'quantitative' => $this->quantitativeAnswer(),
      'aces' => $this->aceAnswer($param),
      'place-slam' => $this->placeSlam(),
      'negative' => $this->negativeDoubleAnswer(),
      'fourth-suit' => $this->fourthSuitAnswer(),
      default => null,
    };
  }

  /**
   * The HCP a seat needs opposite partner's no trump (`$lo`–`$hi`) to
   * invite, bid game, invite a slam (4NT) and bid one (after Gerber).
   * Over 2NT and higher there is no room to invite: game starts where an
   * invitation would.
   *
   * @return array{invite: int, game: int, low: int, quantitative: int, slam: int}
   */
  private function ntZones(int $level, int $lo, int $hi): array
  {
    $game = max(0, self::GAME - ($level === 1 ? $lo : $hi));

    return [
      'invite' => max(0, self::GAME - $hi),
      'game' => $game,
      'low' => $level === 1 ? max(0, self::GAME - $hi) : $game,
      'quantitative' => self::SLAM - $hi,
      'slam' => self::SLAM - $lo,
    ];
  }

  /**
   * Partner opened 1NT or 2NT, or rebid 2NT after 2♣: Gerber, Stayman,
   * Jacoby transfers, a quantitative 4NT, or no trump.
   */
  private function ntResponse(): void
  {
    $v = $this->v;
    $nt = $v->lastAction($v->partner());
    $level = AuctionView::level($v->call($nt));
    $z = $this->ntZones($level, $v->meaning($nt)->min, $v->meaning($nt)->max);
    $up = $level + 1;

    $this->add('4C', new BidMeaning('Gerber', $z['slam'], note: 'asks for aces', asks: 'aces:gerber', alert: true),
      fn (RobotHand $h) => $h->hcp() >= $z['slam']);
    $this->add("{$up}C", new BidMeaning('Stayman', $z['low'], $z['slam'] - 1, note: 'asks for a four-card major', asks: 'stayman', alert: true),
      fn (RobotHand $h) => $this->between($h, $z['low'], $z['slam'] - 1) && $this->staymanShape($h));

    foreach (['S' => 'H', 'H' => 'D'] as $major => $via) {
      $this->add("$up$via", new BidMeaning('Transfer', 0, $z['slam'] - 1, [$major => 5], note: 'asks partner to bid '.BidMeaning::symbol($major), asks: "transfer:$major", suit: $major, alert: true),
        fn (RobotHand $h) => $h->hcp() < $z['slam'] && $h->longest(RobotHand::MAJORS, 5) === $major);
    }

    $this->add('4NT', new BidMeaning('Quantitative', $z['quantitative'], $z['slam'] - 1, note: 'invites 6NT', asks: 'quantitative', suit: 'NT'),
      fn (RobotHand $h) => $h->hcp() >= $z['quantitative']);
    $this->add('3NT', new BidMeaning('Game', $z['game'], $z['quantitative'] - 1, suit: 'NT', signoff: true),
      fn (RobotHand $h) => $h->hcp() >= $z['game']);

    if ($level === 1) {
      $this->add('2NT', new BidMeaning('Invitation', $z['invite'], $z['game'] - 1, invite: true, suit: 'NT'),
        fn (RobotHand $h) => $h->hcp() >= $z['invite']);
    }

    $this->add(AuctionView::PASS, new BidMeaning('Pass', 0, $z['low'] - 1), fn () => true);
  }

  /**
   * A four-card major, or five of one and four of the other.
   */
  private function staymanShape(RobotHand $h): bool
  {
    $long = max($h->length('S'), $h->length('H'));
    $short = min($h->length('S'), $h->length('H'));

    return $long === 4 || ($long === 5 && $short === 4);
  }

  private function staymanAnswer(): void
  {
    $v = $this->v;

    $this->add($v->cheapest('H'), new BidMeaning('Stayman answer', lengths: ['H' => 4], note: 'four hearts', asks: 'after-stayman', suit: 'H'),
      fn (RobotHand $h) => $h->length('H') >= 4);
    $this->add($v->cheapest('S'), new BidMeaning('Stayman answer', lengths: ['S' => 4], note: 'four spades, not four hearts', asks: 'after-stayman', suit: 'S'),
      fn (RobotHand $h) => $h->length('S') >= 4);
    $this->add($v->cheapest('D'), new BidMeaning('Stayman answer', note: 'no four-card major', asks: 'after-stayman'),
      fn () => true);
  }

  /**
   * We asked Stayman: game or an invitation in the major partner showed,
   * a five-card major forcing to game, or no trump.
   */
  private function afterStayman(): void
  {
    $v = $this->v;
    $level = AuctionView::level($v->call($v->lastAction($v->seat))) - 1;
    $p = $v->shown($v->partner());
    $z = $this->ntZones($level, $p['min'], $p['max']);
    $answer = $v->meaning($v->lastAction($v->partner()))->suit;

    if ($answer !== null) {
      $this->add("4$answer", new BidMeaning('Game', $z['game'], $z['slam'] - 1, [$answer => 4], suit: $answer, signoff: true),
        fn (RobotHand $h) => $h->length($answer) >= 4 && $h->hcp() >= $z['game']);

      if ($level === 1) {
        $this->add("3$answer", new BidMeaning('Invitation', $z['invite'], $z['game'] - 1, [$answer => 4], invite: true, suit: $answer),
          fn (RobotHand $h) => $h->length($answer) >= 4);
      }
    } else {
      foreach (RobotHand::MAJORS as $major) {
        $this->add("3$major", new BidMeaning('Game force', $z['game'], $z['slam'] - 1, [$major => 5], force: BidMeaning::GAME, suit: $major),
          fn (RobotHand $h) => $h->hcp() >= $z['game'] && $h->longest(RobotHand::MAJORS, 5) === $major);
      }
    }

    // over 2♥ we have four spades, or we wouldn't have asked
    $spades = $answer === 'H' ? ['S' => 4] : [];

    $this->add('4NT', new BidMeaning('Quantitative', $z['quantitative'], $z['slam'] - 1, $spades, note: 'invites 6NT', asks: 'quantitative', suit: 'NT'),
      fn (RobotHand $h) => $h->hcp() >= $z['quantitative']);
    $this->add('3NT', new BidMeaning('Game', $z['game'], $z['quantitative'] - 1, $spades, asks: $answer === 'H' ? 'choose:S' : null, suit: 'NT', signoff: $answer !== 'H'),
      fn (RobotHand $h) => $h->hcp() >= $z['game']);

    if ($level === 1) {
      $this->add('2NT', new BidMeaning('Invitation', $z['invite'], $z['game'] - 1, $spades, invite: true, suit: 'NT'), fn () => true);
    }
  }

  /**
   * Partner transferred: bid their major, jumping with four of it and a
   * maximum 1NT.
   */
  private function transferAnswer(string $major): void
  {
    $v = $this->v;
    $top = $v->shown($v->seat)['max'];

    if (AuctionView::level($v->call($v->lastAction($v->partner()))) === 2) {
      $this->add("3$major", new BidMeaning('Super-accept', $top, $top, [$major => 4], suit: $major, alert: true),
        fn (RobotHand $h) => $h->length($major) >= 4 && $h->hcp() >= $top);
    }

    $this->add($v->cheapest($major), new BidMeaning('Completes the transfer', asks: 'after-transfer'), fn () => true);
  }

  /**
   * Partner completed our transfer: pass weak, invite, or bid game — in
   * the major with six, else in no trump, leaving partner the choice.
   */
  private function afterTransfer(): void
  {
    $v = $this->v;
    $transfer = $v->lastAction($v->seat);
    $major = $v->meaning($transfer)->suit;
    $level = AuctionView::level($v->call($transfer)) - 1;
    $p = $v->shown($v->partner());
    $z = $this->ntZones($level, $p['min'], $p['max']);
    $six = fn (RobotHand $h) => $h->length($major) >= 6;

    $this->add("4$major", new BidMeaning('Game', $z['game'], $z['slam'] - 1, [$major => 6], suit: $major, signoff: true),
      fn (RobotHand $h) => $six($h) && $h->hcp() >= $z['game']);

    if ($level === 1) {
      $this->add("3$major", new BidMeaning('Invitation', $z['invite'], $z['game'] - 1, [$major => 6], invite: true, suit: $major),
        fn (RobotHand $h) => $six($h) && $h->hcp() >= $z['invite']);
    }

    $this->add('4NT', new BidMeaning('Quantitative', $z['quantitative'], $z['slam'] - 1, [$major => 5], note: 'invites 6NT', asks: 'quantitative', suit: 'NT'),
      fn (RobotHand $h) => ! $six($h) && $h->hcp() >= $z['quantitative']);
    $this->add('3NT', new BidMeaning('Choice of games', $z['game'], $z['quantitative'] - 1, [$major => 5], asks: "choose:$major", suit: 'NT'),
      fn (RobotHand $h) => ! $six($h) && $h->hcp() >= $z['game']);

    if ($level === 1) {
      $this->add('2NT', new BidMeaning('Invitation', $z['invite'], $z['game'] - 1, [$major => 5], invite: true, suit: 'NT'),
        fn (RobotHand $h) => $h->hcp() >= $z['invite']);
    }

    $this->add(AuctionView::PASS, new BidMeaning('Pass', 0, $z['low'] - 1), fn () => true);
  }

  /**
   * Partner's 3NT offered a choice: four of `$major` with an eight-card fit.
   */
  private function chooseGame(string $major): void
  {
    $need = 8 - $this->v->shown($this->v->partner())['lengths'][$major];

    $this->add("4$major", new BidMeaning('Game', lengths: [$major => $need], suit: $major, signoff: true),
      fn (RobotHand $h) => $h->length($major) >= $need);
  }

  /**
   * Partner's 4NT invited 6NT: accept with the upper half of our range.
   */
  private function quantitativeAnswer(): void
  {
    $me = $this->v->shown($this->v->seat);
    $middle = (int) ceil(($me['min'] + $me['max']) / 2);

    $this->add('6NT', new BidMeaning('Accepts', $middle, suit: 'NT', signoff: true), fn (RobotHand $h) => $h->hcp() >= $middle);
    $this->add(AuctionView::PASS, new BidMeaning('Declines', max: $middle - 1), fn () => true);
  }

  /**
   * Blackwood (4NT) or Gerber (4♣): one step for each ace, the first for
   * none or all four.
   */
  private function aceAnswer(string $convention): void
  {
    $steps = $convention === 'gerber' ? ['4D', '4H', '4S', '4NT'] : ['5C', '5D', '5H', '5S'];

    foreach ([[0, 4], [1], [2], [3]] as $step => $aces) {
      $this->add($steps[$step], new BidMeaning('Aces', aces: $aces, asks: 'place-slam', alert: true),
        fn (RobotHand $h) => in_array($h->aces(), $aces, true));
    }
  }

  /**
   * We asked for aces and partner answered: a grand slam with all of them
   * and 37 points, a small slam missing one at most, otherwise sign off at
   * the five level (four for no trump after Gerber) — in no trump only
   * with the opponents' suits stopped, else in our strain even at six.
   */
  private function placeSlam(): void
  {
    $v = $this->v;
    $aces = $v->meaning($v->lastAction($v->partner()))->aces ?? [0];
    $pmin = $v->shown($v->partner())['min'];
    $last = $v->call($v->lastContract());

    // "none or four": with an ace of our own, partner can't have all four
    $missing = fn (RobotHand $h) => 4 - $h->aces() - ($aces === [0, 4] ? ($h->aces() === 0 ? 4 : 0) : $aces[0]);

    foreach (self::STRAINS as $strain) {
      $is = fn (RobotHand $h) => $this->slamStrain($h) === $strain;
      $signoff = new BidMeaning('Sign-off', note: 'aces missing', suit: $strain, signoff: true);

      $this->add("7$strain", new BidMeaning('Grand slam', suit: $strain, signoff: true),
        fn (RobotHand $h) => $is($h) && $missing($h) === 0 && $h->hcp() + $pmin >= self::GRAND);
      $this->add("6$strain", new BidMeaning('Small slam', suit: $strain, signoff: true),
        fn (RobotHand $h) => $is($h) && $missing($h) <= 1);

      if (AuctionView::strain($last) === $strain) {
        $this->add(AuctionView::PASS, $signoff, $is);
      }

      foreach ([$v->cheapest($strain), $v->cheapest('NT')] as $call) {
        if ($call !== null && AuctionView::level($call) <= 5) {
          $this->add($call, $signoff, AuctionView::strain($call) === 'NT'
            ? fn (RobotHand $h) => $is($h) && $h->stops($this->unstopped())
            : $is);
        }
      }

      // no trump would leave their suit unstopped: our strain, even at six
      if ($strain !== 'NT') {
        $this->add($v->cheapest($strain), $signoff, $is);
      }
    }
  }

  /**
   * Partner opened 2♣ and we waited: 2NT or 3NT balanced, else the
   * longest suit, forcing to game.
   */
  private function strongRebid(): void
  {
    $v = $this->v;

    $this->add('2NT', new BidMeaning('Rebid', 22, 24, balanced: true, asks: 'nt', suit: 'NT', tag: 'nt'),
      fn (RobotHand $h) => $h->isBalanced() && $h->hcp() <= 24);
    $this->add('3NT', new BidMeaning('Rebid', 25, 27, balanced: true, suit: 'NT'),
      fn (RobotHand $h) => $h->isBalanced() && $h->hcp() <= 27);

    foreach (RobotHand::SUITS as $suit) {
      $this->add($v->cheapest($suit), new BidMeaning('Rebid', 22, lengths: [$suit => 5], force: BidMeaning::GAME, asks: 'strong-suit', suit: $suit),
        fn (RobotHand $h) => $h->longest(RobotHand::SUITS) === $suit);
    }
  }

  /**
   * 2♣, 2♦, a suit: raise it (the jump to game is the weaker raise), show
   * a five-card suit, or bid no trump.
   */
  private function afterStrongSuit(): void
  {
    $v = $this->v;
    $suit = $v->meaning($v->lastAction($v->partner()))->suit;
    $isMajor = in_array($suit, RobotHand::MAJORS, true);
    $support = $isMajor ? 3 : 4;
    $others = array_values(array_diff(RobotHand::SUITS, [$suit]));

    $this->add($v->cheapest($suit), new BidMeaning('Raise', 8, lengths: [$suit => $support], suit: $suit),
      fn (RobotHand $h) => $h->length($suit) >= $support && $h->hcp() >= 8);

    if ($isMajor) {
      $this->add("4$suit", new BidMeaning('Raise', 0, 7, [$suit => 3], suit: $suit),
        fn (RobotHand $h) => $h->length($suit) >= 3);
    }

    foreach ($others as $other) {
      $this->add($v->cheapest($other), new BidMeaning('New suit', 8, lengths: [$other => 5], suit: $other),
        fn (RobotHand $h) => $h->hcp() >= 8 && $h->longest($others, 5) === $other);
    }

    if ($v->isLegal('2NT')) {
      $this->add('2NT', new BidMeaning('Negative', 0, 7, suit: 'NT'), fn (RobotHand $h) => $h->hcp() <= 7);
    }

    $this->add('3NT', new BidMeaning('No fit', suit: 'NT'), fn () => true);
  }

  /**
   * We opened, the opponents overcalled and partner made a negative
   * double: show the unbid major, leave the double in with their suit,
   * bid no trump with it stopped, or rebid our suits.
   */
  private function negativeDoubleAnswer(): void
  {
    $v = $this->v;
    $mine = $v->meaning($v->opening())->suit;
    $their = $v->theirSuits();
    $overcall = AuctionView::strain($v->call($v->lastContract()));
    $unbid = array_values(array_filter(RobotHand::MAJORS, fn ($major) => $major !== $mine && ! in_array($major, $their, true)));

    foreach ($unbid as $major) {
      $is = fn (RobotHand $h) => $h->longest($unbid, 4) === $major;

      $this->add($v->cheapest($major), new BidMeaning('Rebid', 12, 15, [$major => 4], suit: $major),
        fn (RobotHand $h) => $is($h) && $h->hcp() <= 15);
      $this->add($v->jump($major), new BidMeaning('Jump rebid', 16, 18, [$major => 4], invite: true, suit: $major),
        fn (RobotHand $h) => $is($h) && $h->hcp() <= 18);
      $this->add("4$major", new BidMeaning('Game', 19, 21, [$major => 4], suit: $major), $is);
    }

    if ($overcall !== 'NT') {
      $this->add(AuctionView::PASS, new BidMeaning('Penalty pass', lengths: [$overcall => 4], note: 'leaves the double in'),
        fn (RobotHand $h) => $h->length($overcall) >= 4 && $h->honours($overcall) >= 2);
    }

    $stopped = fn (RobotHand $h) => $h->isBalanced() && $h->stops($their);
    $this->add($v->cheapest('NT'), new BidMeaning('Rebid', 12, 14, balanced: true, suit: 'NT', stopped: $their),
      fn (RobotHand $h) => $stopped($h) && $h->hcp() <= 14);
    $this->add($v->jump('NT'), new BidMeaning('Jump rebid', 18, 19, balanced: true, suit: 'NT', stopped: $their),
      fn (RobotHand $h) => $stopped($h) && $h->hcp() >= 18);

    $this->add($v->jump($mine), new BidMeaning('Jump rebid', 16, 21, [$mine => 6], invite: true, suit: $mine),
      fn (RobotHand $h) => $h->length($mine) >= 6 && $h->hcp() >= 16);
    $this->add($v->cheapest($mine), new BidMeaning('Rebid', 12, 15, [$mine => 5], suit: $mine),
      fn (RobotHand $h) => $h->length($mine) >= 5 && $h->hcp() <= 15);

    foreach (RobotHand::SUITS as $suit) {
      $call = $v->cheapest($suit);

      if ($suit === $mine || in_array($suit, $their, true) || in_array($suit, $unbid, true)
        || $call === null || AuctionView::level($call) > 2
        || (AuctionView::level($call) === 2 && Suits::strainRank($suit) > Suits::strainRank($mine))) {
        continue;
      }

      $this->add($call, new BidMeaning('New suit', 12, 18, [$suit => 4], suit: $suit),
        fn (RobotHand $h) => $h->length($suit) >= 4 && $h->hcp() <= 18);
    }

    $opened = $v->meaning($v->opening())->lengths[$mine];
    $this->add($v->cheapest($mine), new BidMeaning('Rebid', 12, 21, [$mine => $opened], suit: $mine), fn () => true);
  }

  /**
   * Partner bid the fourth suit: support for their first suit, a stopper
   * for no trump, or our longer suits again.
   */
  private function fourthSuitAnswer(): void
  {
    $v = $this->v;
    [$first, $second] = array_pad($v->suitsOf($v->seat), 2, null);
    $theirs = $v->suitsOf($v->partner())[0];
    $fourth = AuctionView::strain($v->call($v->lastAction($v->partner())));

    if (in_array($theirs, RobotHand::MAJORS, true)) {
      $this->add($v->cheapest($theirs), new BidMeaning('Support', lengths: [$theirs => 3], suit: $theirs),
        fn (RobotHand $h) => $h->length($theirs) >= 3);
    }

    $this->add($v->cheapest('NT'), new BidMeaning('Stopper', note: 'a stopper in '.BidMeaning::symbol($fourth), suit: 'NT'),
      fn (RobotHand $h) => $h->hasStopper($fourth));
    $this->add($v->cheapest($first), new BidMeaning('Rebid', lengths: [$first => 6], suit: $first),
      fn (RobotHand $h) => $h->length($first) >= 6);

    if ($second !== null) {
      $this->add($v->cheapest($second), new BidMeaning('Rebid', lengths: [$second => 5], suit: $second),
        fn (RobotHand $h) => $h->length($second) >= 5);
    }

    $this->add($v->cheapest($first), new BidMeaning('Rebid', lengths: [$first => 5], suit: $first), fn () => true);
  }

  // ---------------------------------------------------------------- overcalls

  /**
   * The opponents opened and our side hasn't called anything but pass: a
   * penalty double of their no trump opening, a no trump overcall, a
   * suit, a takeout double, a weak jump overcall. In the pass-out seat
   * (balancing) the no trump, the suit and the double need less.
   */
  private function overcall(): void
  {
    $v = $this->v;
    $balancing = $v->trailingPasses() === 2;
    $last = $v->call($v->lastContract());
    $level = AuctionView::level($last);
    $their = $v->theirSuits();
    $free = array_values(array_diff(RobotHand::SUITS, $their));
    $theirNt = AuctionView::strain($last) === 'NT';

    if ($theirNt && $v->lastContract() === $v->opening()) {
      if ($level <= 2) {
        $this->add('X', new BidMeaning('Penalty double', 15, tag: 'penalty', alert: true), fn (RobotHand $h) => $h->hcp() >= 15);
      }
    } elseif (! $theirNt) {
      $nt = $v->cheapest('NT');

      if ($balancing && $nt === '1NT') {
        $this->add('1NT', new BidMeaning('Balancing 1NT', 11, 14, balanced: true, suit: 'NT', tag: 'nt-overcall', stopped: $their),
          fn (RobotHand $h) => $h->isBalanced() && $this->between($h, 11, 14) && $h->stops($their));
      } elseif (! $balancing && self::levelOf($nt) <= 2) {
        $this->add($nt, new BidMeaning('Overcall', 15, 18, balanced: true, suit: 'NT', tag: 'nt-overcall', stopped: $their),
          fn (RobotHand $h) => $h->isBalanced() && $this->between($h, 15, 18) && $h->stops($their));
      }
    }

    $least = [1 => $balancing ? 6 : 8, 2 => $balancing ? 9 : 11, 3 => 13];

    foreach ($free as $suit) {
      $call = $v->cheapest($suit);

      if ($call === null || AuctionView::level($call) > 3) {
        continue;
      }

      $min = $least[AuctionView::level($call)];
      $length = AuctionView::level($call) === 3 ? 6 : 5;

      $this->add($call, new BidMeaning('Overcall', $min, 17, [$suit => $length], suit: $suit, tag: 'overcall'),
        fn (RobotHand $h) => $h->longest($free, $length) === $suit && $this->between($h, $min, 17));
    }

    // over a suit up to the 3 level, or their 1NT answer: the suits they've bid
    if ($level <= ($theirNt ? 1 : 3) && count($their) >= 1 && count($their) <= 2) {
      $need = count($free) === 3 ? 3 : 4;
      $min = $balancing ? 9 : 12;
      $double = new BidMeaning('Takeout double', $min, note: 'short in '.implode('', array_map(BidMeaning::symbol(...), $their)).', asks partner to pick a suit', asks: 'takeout', tag: 'takeout');

      $this->add('X', $double, fn (RobotHand $h) => $h->hcp() >= $min
        && array_filter($their, fn ($suit) => $h->length($suit) > 2) === []
        && array_filter($free, fn ($suit) => $h->length($suit) < $need) === []);
      // too strong to overcall: double first
      $this->add('X', $double, fn (RobotHand $h) => $h->hcp() >= 18);
    }

    if (! $balancing) {
      foreach ($free as $suit) {
        $call = $v->jump($suit);

        if ($call !== null && AuctionView::level($call) <= 3) {
          $this->add($call, new BidMeaning('Weak jump overcall', 5, 10, [$suit => 6], suit: $suit, tag: 'weak'),
            fn (RobotHand $h) => $this->between($h, 5, 10) && $h->length($suit) >= 6 && $h->isGoodSuit($suit) && $h->longest($free) === $suit);
        }
      }
    }
  }

  /**
   * Partner overcalled or doubled and we haven't called anything but pass.
   */
  private function advance(): void
  {
    $v = $this->v;
    $first = $v->meaning($v->actions($v->partner())[0]);

    match ($first->tag) {
      'takeout' => $this->advanceDouble(),
      'overcall' => $this->advanceOvercall($first->suit),
      'weak' => $this->raiseWeak($first->suit),
      'nt-overcall' => $this->advanceNt($first),
      'penalty' => null,
      default => $this->placement(),
    };
  }

  /**
   * Partner's takeout double: we must bid — an unbid major first, then no
   * trump with their suit stopped (`naturalNt()`), then our longest suit — jumping with
   * 9–11, bidding game with 12+. Over an opponent's bid (or a round
   * later), bid only with something to say. A pass of their one-level bid
   * is for penalties.
   */
  private function advanceDouble(): void
  {
    $v = $this->v;
    $their = $v->theirSuits();
    $unbid = array_values(array_diff(RobotHand::SUITS, $their));
    $majors = array_values(array_intersect(RobotHand::MAJORS, $unbid));
    $last = $v->call($v->lastContract());

    // the opponent bid over the double, or it was rounds ago: we're off the hook
    if (AuctionView::isContract($v->lastCall()) || $v->lastAction($v->partner()) !== count($v->calls) - 2) {
      foreach ($majors as $major) {
        $this->add("4$major", new BidMeaning('Game', 12, lengths: [$major => 4], suit: $major),
          fn (RobotHand $h) => $h->hcp() >= 12 && $h->longest($majors, 4) === $major);
      }

      foreach ($unbid as $suit) {
        $call = $v->cheapest($suit);
        $length = in_array($suit, RobotHand::MAJORS, true) ? 4 : 5;

        if ($call !== null && AuctionView::level($call) <= 3) {
          $this->add($call, new BidMeaning('Free advance', 6, 11, [$suit => $length], suit: $suit),
            fn (RobotHand $h) => $this->between($h, 6, 11) && $h->longest($unbid, $length) === $suit);
        }
      }

      return;
    }

    $theirs = AuctionView::strain($last);

    if (AuctionView::level($last) === 1 && $theirs !== 'NT') {
      $this->add(AuctionView::PASS, new BidMeaning('Penalty pass', 8, lengths: [$theirs => 5], note: 'leaves the double in'),
        fn (RobotHand $h) => $h->hcp() >= 8 && $h->length($theirs) >= 5 && $h->honours($theirs) >= 2);
    }

    foreach ($majors as $major) {
      $is = fn (RobotHand $h) => $h->longest($majors, 4) === $major;
      $jump = $v->jump($major);

      $this->add($v->cheapest($major), new BidMeaning('Advance', 0, 8, [$major => 4], suit: $major),
        fn (RobotHand $h) => $is($h) && $h->hcp() <= 8);

      if ($jump !== "4$major") {
        $this->add($jump, new BidMeaning('Jump advance', 9, 11, [$major => 4], invite: true, suit: $major),
          fn (RobotHand $h) => $is($h) && $h->hcp() <= 11);
      }

      $this->add("4$major", new BidMeaning('Game', $jump === "4$major" ? 9 : 12, lengths: [$major => 4], suit: $major), $is);
    }

    $this->naturalNt($their, [[6, 10], [11, 12], [13, null]]);

    foreach ($unbid as $suit) {
      $is = fn (RobotHand $h) => $h->longest($unbid) === $suit;

      $this->add($v->cheapest($suit), new BidMeaning('Advance', 0, 8, [$suit => 3], suit: $suit),
        fn (RobotHand $h) => $is($h) && $h->hcp() <= 8);
      $this->add($v->jump($suit), new BidMeaning('Jump advance', 9, lengths: [$suit => 3], invite: true, suit: $suit), $is);
    }
  }

  /**
   * Partner overcalled in a suit: raise it (a jump invites, a major game
   * with 14+, 3NT over a minor with their suits stopped and no singleton),
   * show a five-card suit, or bid no trump with their suit stopped.
   */
  private function advanceOvercall(string $suit): void
  {
    $v = $this->v;
    $their = $v->theirSuits();
    $support = fn (RobotHand $h) => $h->length($suit) >= 3;
    $jump = $v->jump($suit);

    $this->add($v->cheapest($suit), new BidMeaning('Raise', 6, 10, [$suit => 3], suit: $suit),
      fn (RobotHand $h) => $support($h) && $this->between($h, 6, 10));

    if (in_array($suit, RobotHand::MAJORS, true)) {
      if ($jump !== "4$suit") {
        $this->add($jump, new BidMeaning('Jump raise', 11, 13, [$suit => 3], invite: true, suit: $suit),
          fn (RobotHand $h) => $support($h) && $this->between($h, 11, 13));
      }

      $this->add("4$suit", new BidMeaning('Game', $jump === "4$suit" ? 11 : 14, lengths: [$suit => 3], suit: $suit),
        fn (RobotHand $h) => $support($h) && $h->hcp() >= 11);
    } else {
      $this->add('3NT', new BidMeaning('Game', 14, suit: 'NT', stopped: $their),
        fn (RobotHand $h) => $support($h) && $h->hcp() >= 14 && $h->stops($their) && $h->isSemiBalanced());
      $this->add($jump, new BidMeaning('Jump raise', 11, lengths: [$suit => 3], invite: true, suit: $suit),
        fn (RobotHand $h) => $support($h) && $h->hcp() >= 11);
    }

    $others = array_values(array_diff(RobotHand::SUITS, $their, [$suit]));

    foreach ($others as $other) {
      $call = $v->cheapest($other);

      if ($call !== null && AuctionView::level($call) <= 2) {
        $this->add($call, new BidMeaning('New suit', 8, 15, [$other => 5], suit: $other),
          fn (RobotHand $h) => $this->between($h, 8, 15) && $h->longest($others, 5) === $other);
      }
    }

    $this->naturalNt($their, [[8, 11], [12, 14], [15, null]]);
  }

  /**
   * One-level no trump if still possible, the 2NT invitation and 3NT,
   * with the opponents' suits stopped, over the given HCP ranges: balanced,
   * or for 3NT a good six-card minor and no singleton.
   *
   * @param  list<string>  $their
   * @param  array{0: array{int, int}, 1: array{int, int}, 2: array{int, null}}  $ranges
   */
  private function naturalNt(array $their, array $ranges): void
  {
    [[$oneMin, $oneMax], [$twoMin, $twoMax], [$threeMin]] = $ranges;
    $balanced = fn (RobotHand $h) => $h->isBalanced() && $h->stops($their);

    if ($this->v->cheapest('NT') === '1NT') {
      $this->add('1NT', new BidMeaning('Natural', $oneMin, $oneMax, balanced: true, suit: 'NT', stopped: $their),
        fn (RobotHand $h) => $balanced($h) && $this->between($h, $oneMin, $oneMax));
    }

    $this->add('2NT', new BidMeaning('Invitation', $twoMin, $twoMax, balanced: true, invite: true, suit: 'NT', stopped: $their),
      fn (RobotHand $h) => $balanced($h) && $this->between($h, $twoMin, $twoMax));
    $this->add('3NT', new BidMeaning('Game', $threeMin, suit: 'NT', stopped: $their),
      fn (RobotHand $h) => $h->stops($their) && $h->hcp() >= $threeMin && $this->isNtGameShape($h));
  }

  /**
   * A hand for game in no trump of our own choosing: balanced, or a good
   * six-card (or longer) minor to run with no singleton or void.
   */
  private function isNtGameShape(RobotHand $h): bool
  {
    $minor = $h->longest(RobotHand::MINORS, 6);

    return $h->isBalanced() || ($h->isSemiBalanced() && $minor !== null && $h->isGoodSuit($minor));
  }

  /**
   * Partner's weak two, or weak jump overcall: game in a major with 16+
   * and two cards of it, 3NT with 16+ balanced and the other suits
   * stopped, a preemptive raise with three; nothing else.
   */
  private function raiseWeak(string $suit): void
  {
    $others = array_values(array_diff(RobotHand::SUITS, [$suit]));

    if (in_array($suit, RobotHand::MAJORS, true)) {
      $this->add("4$suit", new BidMeaning('Game', 16, lengths: [$suit => 2], suit: $suit, signoff: true),
        fn (RobotHand $h) => $h->length($suit) >= 2 && $h->hcp() >= 16);
    }

    $this->add('3NT', new BidMeaning('Game', 16, balanced: true, suit: 'NT', signoff: true, stopped: $others),
      fn (RobotHand $h) => $h->hcp() >= 16 && $h->isBalanced() && $h->stops($others));
    $this->add($this->v->cheapest($suit), new BidMeaning('Preemptive raise', 6, 15, [$suit => 3], suit: $suit, signoff: true),
      fn (RobotHand $h) => $h->length($suit) >= 3 && $this->between($h, 6, 15));
  }

  /**
   * Partner overcalled in no trump: 3NT with game values, 2NT between.
   */
  private function advanceNt(BidMeaning $nt): void
  {
    $game = self::GAME - $nt->min;
    $invite = self::GAME - $nt->max;

    $this->add('3NT', new BidMeaning('Game', $game, suit: 'NT', signoff: true), fn (RobotHand $h) => $h->hcp() >= $game);

    if ($this->v->cheapest('NT') === '2NT') {
      $this->add('2NT', new BidMeaning('Invitation', $invite, $game - 1, invite: true, suit: 'NT'),
        fn (RobotHand $h) => $h->hcp() >= $invite);
    }
  }

  // ---------------------------------------------------------------- responses

  /**
   * Partner opened and we haven't called anything but pass.
   */
  private function response(): void
  {
    $opening = $this->v->meaning($this->v->opening());

    match ($opening->tag) {
      'nt' => $this->competeOverNt(),
      'weak' => $this->raiseWeak($opening->suit),
      'preempt' => $this->raisePreempt($opening->suit),
      'one' => $this->suitResponse($opening->suit),
      default => null,
    };
  }

  /**
   * An opponent bid over partner's no trump (so Stayman and transfers are
   * off): double them with four of their suit, 3NT with their suits
   * stopped, or a five-card suit of our own.
   */
  private function competeOverNt(): void
  {
    $v = $this->v;
    $last = $v->call($v->lastContract());
    $theirs = AuctionView::strain($last);
    $their = $v->theirSuits();

    if ($theirs !== 'NT' && AuctionView::level($last) <= 3) {
      $this->add('X', new BidMeaning('Penalty double', 8, lengths: [$theirs => 4], tag: 'penalty'),
        fn (RobotHand $h) => $h->hcp() >= 8 && $h->length($theirs) >= 4);
    }

    $this->add('3NT', new BidMeaning('Game', 10, suit: 'NT', signoff: true, stopped: $their),
      fn (RobotHand $h) => $h->hcp() >= 10 && $h->stops($their));

    $mine = array_values(array_diff(RobotHand::SUITS, [$theirs]));

    foreach ($mine as $suit) {
      $call = $v->cheapest($suit);

      if ($call !== null && AuctionView::level($call) <= 3) {
        $this->add($call, new BidMeaning('Natural', 5, lengths: [$suit => 5], suit: $suit, signoff: true),
          fn (RobotHand $h) => $h->hcp() >= 5 && $h->longest($mine, 5) === $suit);
      }
    }
  }

  /**
   * Partner's three-level preempt (or four of a major): game with 16+.
   */
  private function raisePreempt(string $suit): void
  {
    $others = array_values(array_diff(RobotHand::SUITS, [$suit]));

    if (in_array($suit, RobotHand::MAJORS, true)) {
      $this->add("4$suit", new BidMeaning('Game', 16, lengths: [$suit => 2], suit: $suit, signoff: true),
        fn (RobotHand $h) => $h->length($suit) >= 2 && $h->hcp() >= 16);
    } else {
      $this->add('3NT', new BidMeaning('Game', 16, suit: 'NT', signoff: true, stopped: $others),
        fn (RobotHand $h) => $h->hcp() >= 16 && $h->stops($others));
    }
  }

  /**
   * Partner opened one of a suit, perhaps with an overcall or a double in
   * between.
   */
  private function suitResponse(string $opened): void
  {
    $v = $this->v;
    $overcalled = ! $v->isOurs($v->seatOf($v->lastContract()));
    $their = $v->theirSuits();
    $new = array_values(array_diff(RobotHand::SUITS, [$opened], $their));
    $jumpShift = new BidMeaning('Jump shift', 19, force: BidMeaning::GAME, tag: 'jump-shift');

    // a jump shift: 19+ and a five-card suit
    foreach ($new as $suit) {
      $this->add($v->jump($suit), $this->withSuit($jumpShift, $suit, 5),
        fn (RobotHand $h) => $h->hcp() >= 19 && $h->longest($new, 5) === $suit);
    }

    // raises of a major: 2 with 6–10, 3 (a limit raise) with 11–12, 4 with 13+
    if (in_array($opened, RobotHand::MAJORS, true)) {
      $support = fn (RobotHand $h) => $h->length($opened) >= 3;

      $this->add("2$opened", new BidMeaning('Raise', 6, 10, [$opened => 3], suit: $opened, tag: 'raise'),
        fn (RobotHand $h) => $support($h) && $this->between($h, 6, 10));
      $this->add("3$opened", new BidMeaning('Limit raise', 11, 12, [$opened => 3], invite: true, suit: $opened, tag: 'raise'),
        fn (RobotHand $h) => $support($h) && $this->between($h, 11, 12));
      $this->add("4$opened", new BidMeaning('Game raise', 13, lengths: [$opened => 3], suit: $opened, tag: 'raise'),
        fn (RobotHand $h) => $support($h) && $h->hcp() >= 13);
    }

    $this->add(AuctionView::PASS, $overcalled ? new BidMeaning('Pass', known: false) : new BidMeaning('Pass', 0, 5),
      fn (RobotHand $h) => $h->hcp() < 6);

    // a jump shift in a four-card suit, with no five-card one
    foreach ($new as $suit) {
      $this->add($v->jump($suit), $this->withSuit($jumpShift, $suit, 4),
        fn (RobotHand $h) => $h->hcp() >= 19 && $h->longest($new, 4) === $suit);
    }

    if ($overcalled) {
      $this->negativeDouble($opened, $their);
    }

    // a new major at the 1 level: hearts first with 4-4, spades with 5-5
    $majors = array_values(array_filter($new, fn ($suit) => in_array($suit, RobotHand::MAJORS, true)
      && self::levelOf($v->cheapest($suit)) === 1));

    foreach ($majors as $major) {
      $this->add($v->cheapest($major), new BidMeaning('New suit', 6, 18, [$major => 4], force: BidMeaning::ROUND, suit: $major, tag: 'new-suit'),
        fn (RobotHand $h) => $this->between($h, 6, 18) && $this->majorToShow($h, $majors) === $major);
    }

    if ($opened === 'C' && in_array('D', $new, true) && $v->cheapest('D') === '1D') {
      $this->add('1D', new BidMeaning('New suit', 6, 18, ['D' => 4], force: BidMeaning::ROUND, suit: 'D', tag: 'new-suit'),
        fn (RobotHand $h) => $this->between($h, 6, 18) && $h->length('D') >= 4);
    }

    if ($overcalled) {
      $this->naturalNt($their, [[6, 10], [11, 12], [13, null]]);
    } else {
      $this->add('2NT', new BidMeaning('Response', 13, 15, balanced: true, suit: 'NT', tag: 'nt-response'),
        fn (RobotHand $h) => $h->isBalanced() && $this->between($h, 13, 15));
      $this->add('3NT', new BidMeaning('Response', 16, 18, balanced: true, suit: 'NT', tag: 'nt-response'),
        fn (RobotHand $h) => $h->isBalanced() && $this->between($h, 16, 18));
    }

    // a new suit at the 2 level: 11+
    $twos = array_values(array_filter($new, fn ($suit) => self::levelOf($v->cheapest($suit)) === 2));

    foreach ($twos as $suit) {
      $this->add($v->cheapest($suit), new BidMeaning('New suit', 11, 18, [$suit => 4], force: BidMeaning::ROUND, suit: $suit, tag: 'new-suit'),
        fn (RobotHand $h) => $this->between($h, 11, 18) && $h->longest($twos, 4) === $suit);
    }

    // raises of a minor, with no major to show
    if (in_array($opened, RobotHand::MINORS, true)) {
      $length = $opened === 'C' ? 5 : 4;

      $this->add("2$opened", new BidMeaning('Raise', 6, 10, [$opened => $length], suit: $opened, tag: 'raise'),
        fn (RobotHand $h) => $h->length($opened) >= $length && $this->between($h, 6, 10));
      $this->add("3$opened", new BidMeaning('Limit raise', 11, 12, [$opened => 4], invite: true, suit: $opened, tag: 'raise'),
        fn (RobotHand $h) => $h->length($opened) >= 4 && $this->between($h, 11, 12));
    }

    if (! $overcalled) {
      $this->add('1NT', new BidMeaning('Response', 6, 10, suit: 'NT', tag: 'nt-response'),
        fn (RobotHand $h) => $this->between($h, 6, 10));
    }
  }

  private function withSuit(BidMeaning $meaning, string $suit, int $length): BidMeaning
  {
    return new BidMeaning($meaning->label, $meaning->min, $meaning->max, [$suit => $length], force: $meaning->force, suit: $suit, tag: $meaning->tag);
  }

  /**
   * The major to answer with: hearts with four of each, else the longer
   * (spades with 5-5).
   *
   * @param  list<string>  $majors  the ones we may bid
   */
  private function majorToShow(RobotHand $h, array $majors): ?string
  {
    if (count($majors) === 2 && $h->length('H') === 4 && $h->length('S') <= 4) {
      return 'H';
    }

    return $h->longest($majors, 4);
  }

  /**
   * An opponent overcalled partner's opening: a negative double shows the
   * unbid major(s) — both minors when both majors are gone — up to 2♠. A
   * double of their 1NT is for penalties.
   *
   * @param  list<string>  $their
   */
  private function negativeDouble(string $opened, array $their): void
  {
    $v = $this->v;
    $overcall = $v->call($v->lastContract());

    if (AuctionView::strain($overcall) === 'NT') {
      $this->add('X', new BidMeaning('Penalty double', 10, tag: 'penalty', alert: true), fn (RobotHand $h) => $h->hcp() >= 10);

      return;
    }

    if (AuctionView::rank($overcall) > AuctionView::rank('2S')) {
      return;
    }

    $unbid = array_values(array_diff(RobotHand::MAJORS, [$opened], $their));

    if ($unbid === []) {
      $unbid = array_values(array_diff(RobotHand::MINORS, [$opened], $their));
    }

    $min = AuctionView::level($overcall) === 1 ? 6 : 8;
    $oneLevel = array_values(array_filter($unbid, fn ($suit) => self::levelOf($v->cheapest($suit)) === 1));

    $this->add('X', new BidMeaning('Negative double', $min, lengths: array_fill_keys($unbid, 4), asks: 'negative', tag: 'negative', alert: true),
      fn (RobotHand $h) => $h->hcp() >= $min
        && array_filter($unbid, fn ($suit) => $h->length($suit) < 4) === []
        // a five-card major goes in at the one level instead
        && $h->longest($oneLevel, 5) === null);
  }

  // ---------------------------------------------------------------- rebids

  /**
   * We opened, partner has made one call, and the opponents haven't bid
   * since.
   */
  private function isOpenersRebid(): bool
  {
    $v = $this->v;
    $partners = $v->actions($v->partner());

    return $v->seatOf($v->opening()) === $v->seat
      && count($v->actions($v->seat)) === 1
      && count($partners) === 1
      && $partners[0] === count($v->calls) - 2
      && in_array($v->lastCall(), [AuctionView::PASS, AuctionView::DOUBLE], true);
  }

  private function openersRebid(): void
  {
    $v = $this->v;
    $opening = $v->meaning($v->opening());
    $response = $v->meaning($v->lastAction($v->partner()));

    if ($opening->tag !== 'one') {
      $this->placement();

      return;
    }

    if (in_array($response->tag, ['new-suit', 'jump-shift'], true)) {
      $this->rebidOverNewSuit($opening->suit, $response->suit);

      return;
    }

    if ($v->call($v->lastAction($v->partner())) === '1NT') {
      $this->rebidOverOneNt($opening->suit);
    }

    $this->placement();
  }

  /**
   * Partner answered in a new suit, which is forcing, so this never
   * passes: raise their major with four, show a balanced hand in no trump
   * (the opponents' suits stopped, if they bid), rebid a six-card suit,
   * jump shift with 19+, show a new suit (a reverse needs 17+), then our
   * five-card suit, 1NT, a raise with three, or our suit again.
   */
  private function rebidOverNewSuit(string $mine, string $theirs): void
  {
    $v = $this->v;
    $twoLevel = AuctionView::level($v->call($v->lastAction($v->partner()))) >= 2;
    $opponents = $v->theirSuits();

    if (in_array($theirs, RobotHand::MAJORS, true)) {
      $four = fn (RobotHand $h) => $h->length($theirs) >= 4;

      $this->add($v->cheapest($theirs), new BidMeaning('Raise', 12, 15, [$theirs => 4], suit: $theirs),
        fn (RobotHand $h) => $four($h) && $h->hcp() <= 15);
      $this->add($v->jump($theirs), new BidMeaning('Jump raise', 16, 18, [$theirs => 4], invite: true, suit: $theirs),
        fn (RobotHand $h) => $four($h) && $h->hcp() <= 18);
      $this->add("4$theirs", new BidMeaning('Game raise', 19, 21, [$theirs => 4], suit: $theirs), $four);
    }

    $new = array_values(array_diff(RobotHand::SUITS, [$mine, $theirs]));

    // a new suit at the 1 level comes before no trump: 1♣–1♦–1♥
    $this->newSuitRebids($mine, $new, 1, 1);

    $balanced = fn (RobotHand $h) => $h->isBalanced() && $h->stops($opponents);
    $this->add($twoLevel ? '2NT' : '1NT', new BidMeaning('Rebid', 12, 14, balanced: true, suit: 'NT', stopped: $opponents),
      fn (RobotHand $h) => $balanced($h) && $h->hcp() <= 14);
    $this->add($twoLevel ? '3NT' : '2NT', new BidMeaning('Rebid', 18, 19, balanced: true, suit: 'NT', stopped: $opponents),
      fn (RobotHand $h) => $balanced($h) && $this->between($h, 18, 19));

    $this->rebidOwnSuit($mine);

    foreach ($new as $suit) {
      $this->add($v->jump($suit), new BidMeaning('Jump shift', 19, 21, [$suit => 4], force: BidMeaning::GAME, suit: $suit, tag: 'jump-shift'),
        fn (RobotHand $h) => $h->hcp() >= 19 && $h->longest($new, 4) === $suit);
    }

    $this->newSuitRebids($mine, $new, 2, 2);

    // partner's minor, with four of it: a jump with 19+
    if (in_array($theirs, RobotHand::MINORS, true)) {
      $this->add($v->cheapest($theirs), new BidMeaning('Raise', 12, 18, [$theirs => 4], suit: $theirs),
        fn (RobotHand $h) => $h->length($theirs) >= 4 && $h->hcp() <= 18);
      $this->add($v->jump($theirs), new BidMeaning('Jump raise', 19, 21, [$theirs => 4], force: BidMeaning::GAME, suit: $theirs),
        fn (RobotHand $h) => $h->length($theirs) >= 4);
    }

    // a hand that gets this far has at most 18 (docs/ROBOTS.md)
    $opened = $v->meaning($v->opening())->lengths[$mine];
    $this->add($v->cheapest($mine), new BidMeaning('Rebid', 12, 18, [$mine => 5], suit: $mine),
      fn (RobotHand $h) => $h->length($mine) >= 5);
    $this->add('1NT', new BidMeaning('Rebid', 12, 14, suit: 'NT', stopped: $opponents),
      fn (RobotHand $h) => $h->hcp() <= 14 && $h->stops($opponents));
    $this->add($v->cheapest($theirs), new BidMeaning('Raise', 12, 18, [$theirs => 3], suit: $theirs),
      fn (RobotHand $h) => $h->length($theirs) >= 3);
    $this->add($v->cheapest($mine), new BidMeaning('Rebid', 12, 18, [$mine => $opened], suit: $mine), fn () => true);
  }

  /**
   * Partner's 1NT answer isn't forcing: rebid a six-card suit, or show a
   * second suit; a balanced hand goes on to `placement()`.
   */
  private function rebidOverOneNt(string $mine): void
  {
    $this->rebidOwnSuit($mine);
    $this->newSuitRebids($mine, array_values(array_diff(RobotHand::SUITS, [$mine])), 2, 2);
  }

  /**
   * A six-card suit again: cheaply with 12–15, a jump with 16–18, game
   * in a major with 19+.
   */
  private function rebidOwnSuit(string $mine): void
  {
    $v = $this->v;
    $six = fn (RobotHand $h) => $h->length($mine) >= 6;

    $this->add($v->cheapest($mine), new BidMeaning('Rebid', 12, 15, [$mine => 6], suit: $mine),
      fn (RobotHand $h) => $six($h) && $h->hcp() <= 15);

    if (in_array($mine, RobotHand::MAJORS, true)) {
      $this->add($v->jump($mine), new BidMeaning('Jump rebid', 16, 18, [$mine => 6], invite: true, suit: $mine),
        fn (RobotHand $h) => $six($h) && $h->hcp() <= 18);
      $this->add("4$mine", new BidMeaning('Game', 19, 21, [$mine => 6], suit: $mine), $six);
    } else {
      $this->add($v->jump($mine), new BidMeaning('Jump rebid', 16, 21, [$mine => 6], invite: true, suit: $mine), $six);
    }
  }

  /**
   * A new four-card suit (the longest; the higher on a tie) bid at the
   * `$minLevel`–`$maxLevel` level: at the 2 level a suit above ours is a
   * reverse, which needs 17+ and forces a round.
   *
   * @param  list<string>  $new
   */
  private function newSuitRebids(string $mine, array $new, int $minLevel, int $maxLevel): void
  {
    $v = $this->v;
    $inRange = fn (string $suit) => self::levelOf($v->cheapest($suit)) >= $minLevel && self::levelOf($v->cheapest($suit)) <= $maxLevel;
    $reverse = fn (string $suit) => self::levelOf($v->cheapest($suit)) === 2
      && Suits::strainRank($suit) > Suits::strainRank($mine);
    $eligible = fn (RobotHand $h) => array_values(array_filter($new, fn ($suit) => $inRange($suit) && (! $reverse($suit) || $h->hcp() >= 17)));

    foreach ($new as $suit) {
      $call = $v->cheapest($suit);

      if (! $inRange($suit)) {
        continue;
      }

      $meaning = $reverse($suit)
        ? new BidMeaning('Reverse', 17, 18, [$suit => 4], force: BidMeaning::ROUND, suit: $suit, tag: 'reverse')
        : new BidMeaning('New suit', 12, 18, [$suit => 4], suit: $suit);

      $this->add($call, $meaning, fn (RobotHand $h) => $h->hcp() <= 18 && $h->longest($eligible($h), 4) === $suit);
    }
  }

  // ---------------------------------------------------------------- later bids

  /**
   * Any later call: add our points to what partner has shown and place the
   * contract — fourth suit forcing, a slam through Blackwood or Gerber,
   * game when the total is surely 25+, an invitation when it may be. Over
   * the opponents: double them, compete, or pass.
   */
  private function placement(): void
  {
    $v = $this->v;
    $p = $v->shown($v->partner());
    $last = $v->lastContract();
    $partnersLast = $v->lastAction($v->partner());

    // partner's bids said nothing we understand, the contract is ours
    // already, or partner signed off: leave it where it is
    if (! $p['known'] || $v->seatOf($last) === $v->seat
      || ($partnersLast !== null && $v->meaning($partnersLast)->signoff)) {
      return;
    }

    if (! $v->isOurs($v->seatOf($last))) {
      $this->compete($p);

      return;
    }

    $call = $v->call($last);
    $pmin = $p['min'];
    $partners = $v->meaning($partnersLast);
    $ntPartner = $partners->suit === 'NT' && AuctionView::isContract($v->call($partnersLast))
      && AuctionView::level($v->call($partnersLast)) <= 3;

    $this->fourthSuitForcing($p);

    // a slam: ask for aces first
    if (! $v->askedForAces() && AuctionView::level($call) < 6) {
      $slam = fn (RobotHand $h) => $h->hcp() + $pmin >= self::SLAM;
      $min = self::SLAM - $pmin;

      $this->add($ntPartner ? '4C' : '4NT', new BidMeaning($ntPartner ? 'Gerber' : 'Blackwood', $min, note: 'asks for aces', asks: $ntPartner ? 'aces:gerber' : 'aces:blackwood', alert: true), $slam);

      foreach (self::STRAINS as $strain) {
        $this->add("6$strain", new BidMeaning('Small slam', $min, suit: $strain, signoff: true),
          fn (RobotHand $h) => $slam($h) && $this->slamStrain($h) === $strain);
      }

      // opposite a narrow no trump range only (12–14, 15–17, 18–19 …)
      if ($ntPartner && $p['max'] - $p['min'] <= 4) {
        $this->add('4NT', new BidMeaning('Quantitative', max(0, self::SLAM - $p['max']), $min - 1, note: 'invites 6NT', asks: 'quantitative', suit: 'NT', stopped: $this->unstopped()),
          fn (RobotHand $h) => $h->hcp() + $p['max'] >= self::SLAM && $this->gameStrain($h) === 'NT');
      }
    }

    if (AuctionView::isGame($call)) {
      return;
    }

    if ($v->forcingToGame()) {
      $this->games(null, fn () => true);

      return;
    }

    // no slam: we would have asked for aces
    $game = self::GAME - $pmin;
    $top = self::SLAM - 1 - $pmin;
    $this->games(new BidMeaning('Game', $game, $top), fn (RobotHand $h) => $h->hcp() >= $game);

    $me = $v->shown($v->seat);

    // partner invited: accept with the upper half of what we have shown
    if ($partners->invite) {
      $middle = (int) ceil(($me['min'] + $me['max']) / 2);
      $this->games(new BidMeaning('Accepts', min($middle, $top), $top), fn (RobotHand $h) => $h->hcp() >= $middle);
    } elseif (! $me['invite']) {
      $invite = max(0, self::GAME - $p['max']);

      foreach (['S', 'H', 'NT'] as $strain) {
        $this->add($strain === 'NT' ? '2NT' : "3$strain", new BidMeaning('Invitation', $invite, $game - 1, $this->fitLength($strain), invite: true, suit: $strain,
          stopped: $strain === 'NT' ? $this->unstopped() : []),
          fn (RobotHand $h) => $this->gameStrain($h) === $strain && $h->hcp() >= $invite && $h->hcp() < $game);
      }
    }

    // partner's last bid forces us to bid once more
    if ($partners->force === BidMeaning::ROUND && $partnersLast > ($v->lastAction($v->seat) ?? -1)) {
      $nt = $v->cheapest('NT');

      if ($nt !== null && AuctionView::level($nt) <= 3) {
        $this->add($nt, new BidMeaning('Waiting', suit: 'NT', stopped: $this->unstopped()),
          fn (RobotHand $h) => $h->stops($this->unstopped()));
      }

      foreach ($v->suitsOf($v->partner()) as $suit) {
        $this->add($v->cheapest($suit), new BidMeaning('Preference', suit: $suit), fn () => true);
      }
    }
  }

  /**
   * Game in the strain our hands point to: four of a major we have eight
   * of between us, else 3NT — five of a minor fit once 3NT has gone, or
   * when the opponents' suits aren't stopped.
   *
   * @param  Closure(RobotHand): bool  $when
   */
  private function games(?BidMeaning $meaning, Closure $when): void
  {
    foreach (['S', 'H', 'NT'] as $strain) {
      $game = $strain === 'NT' ? '3NT' : "4$strain";

      $this->add($game, $this->named($meaning, $strain), fn (RobotHand $h) => $when($h) && $this->gameStrain($h) === $strain);
    }

    foreach (RobotHand::MINORS as $minor) {
      $this->add("5$minor", $this->named($meaning, $minor),
        fn (RobotHand $h) => $when($h) && ($this->gameStrain($h) === $minor
          || ($this->gameStrain($h) === 'NT' && $this->minorFit($h) === $minor && ! $this->v->isLegal('3NT'))));
    }
  }

  private function named(?BidMeaning $meaning, string $strain): BidMeaning
  {
    return new BidMeaning($meaning?->label ?? 'Game', $meaning?->min, $meaning?->max, $this->fitLength($strain), suit: $strain,
      stopped: $strain === 'NT' ? $this->unstopped() : []);
  }

  /**
   * What a bid in the strain `gameStrain()` chose shows of it: enough for
   * eight between us (seven for a suit of our own).
   *
   * @return array<string, int>
   */
  private function fitLength(string $strain): array
  {
    if (! in_array($strain, RobotHand::SUITS, true)) {
      return [];
    }

    return [$strain => min(7, 8 - $this->v->shown($this->v->partner())['lengths'][$strain])];
  }

  /**
   * Responder's second bid after three suits, with game values but no fit
   * and no stopper in the fourth: bid it, artificial and forcing to game.
   *
   * @param  array{min: int}  $p
   */
  private function fourthSuitForcing(array $p): void
  {
    $v = $this->v;
    $opening = $v->opening();
    $ours = array_merge($v->suitsOf($v->partner()), $v->suitsOf($v->seat));
    $fourth = array_values(array_diff(RobotHand::SUITS, $ours));

    if ($v->seatOf($opening) !== $v->partner() || count($v->actions($v->seat)) !== 1
      || count($v->actions($v->partner())) !== 2 || count($ours) !== 3 || count($fourth) !== 1
      || $v->theirSuits() !== [] || $v->forcingToGame()) {
      return;
    }

    $call = $v->cheapest($fourth[0]);

    if ($call === null || AuctionView::level($call) > 3) {
      return;
    }

    $min = self::GAME - $p['min'];

    $this->add($call, new BidMeaning('Fourth suit forcing', $min, note: 'artificial', force: BidMeaning::GAME, asks: 'fourth-suit', alert: true),
      fn (RobotHand $h) => $h->hcp() >= $min && $this->gameStrain($h) === 'NT' && ! $h->hasStopper($fourth[0]));
  }

  /**
   * The opponents bid last: double a low contract with their suit and the
   * balance of points, bid game when it is sure (or forced), else compete
   * in our fit as far as the law of total tricks allows — as many tricks
   * as trumps between us, up to the 3 level.
   *
   * @param  array{min: int, lengths: array<string, int>}  $p
   */
  private function compete(array $p): void
  {
    $v = $this->v;
    $last = $v->call($v->lastContract());
    $theirs = AuctionView::strain($last);
    $pmin = $p['min'];

    if (AuctionView::level($last) <= 2 && ! $v->isDoubled()) {
      if ($theirs === 'NT') {
        $this->add('X', new BidMeaning('Penalty double', max(8, 23 - $pmin), tag: 'penalty', alert: true),
          fn (RobotHand $h) => $h->hcp() >= 8 && $h->hcp() + $pmin >= 23);
      } else {
        $this->add('X', new BidMeaning('Penalty double', max(10, 20 - $pmin), lengths: [$theirs => 4], tag: 'penalty'),
          fn (RobotHand $h) => $h->hcp() >= 10 && $h->hcp() + $pmin >= 20 && $h->length($theirs) >= 4 && $h->honours($theirs, 5) >= 2);
      }
    }

    if ($v->forcingToGame()) {
      $this->games(null, fn () => true);
    } else {
      $game = self::GAME - $pmin;
      $this->games(new BidMeaning('Game', $game), fn (RobotHand $h) => $h->hcp() >= $game);
    }

    foreach (RobotHand::SUITS as $suit) {
      $call = $v->cheapest($suit);

      if ($p['lengths'][$suit] === 0 || $call === null || AuctionView::level($call) > 3) {
        continue;
      }

      $need = max(1, AuctionView::level($call) + 6 - $p['lengths'][$suit]);

      $this->add($call, new BidMeaning('Competitive', lengths: [$suit => $need], suit: $suit),
        fn (RobotHand $h) => $h->length($suit) >= $need && $h->length($suit) + $p['lengths'][$suit] >= 8);
    }
  }

  // ---------------------------------------------------------------- strains

  /**
   * Where game should be played: the major with eight or more cards
   * between us (the more, the better; spades on a tie) or a seven-card
   * major of our own, else no trump — when the opponents' suits are
   * stopped (`unstopped()`); without that, a minor fit, else nowhere
   * (null).
   */
  private function gameStrain(RobotHand $h): ?string
  {
    $lengths = $this->v->shown($this->v->partner())['lengths'];
    $best = 'NT';
    $most = 7;

    foreach (RobotHand::MAJORS as $major) {
      if ($h->length($major) + $lengths[$major] > $most) {
        [$best, $most] = [$major, $h->length($major) + $lengths[$major]];
      }
    }

    if ($best === 'NT') {
      $best = $h->longest(RobotHand::MAJORS, 7) ?? 'NT';
    }

    if ($best === 'NT' && ! $h->stops($this->unstopped())) {
      return $this->minorFit($h);
    }

    return $best;
  }

  /**
   * The suits the opponents bid that a no trump of ours needs stopped:
   * all of them but those partner's no trump has promised.
   *
   * @return list<string>
   */
  private function unstopped(): array
  {
    return array_values(array_diff($this->v->theirSuits(), $this->v->shown($this->v->partner())['stopped']));
  }

  /**
   * The minor with eight or more cards between us, if any.
   */
  private function minorFit(RobotHand $h): ?string
  {
    $lengths = $this->v->shown($this->v->partner())['lengths'];
    $best = null;
    $most = 7;

    foreach (RobotHand::MINORS as $minor) {
      if ($h->length($minor) + $lengths[$minor] > $most) {
        [$best, $most] = [$minor, $h->length($minor) + $lengths[$minor]];
      }
    }

    return $best;
  }

  /**
   * Where a slam should be played: a major fit, a minor fit, a six-card
   * suit of our own, or no trump.
   */
  private function slamStrain(RobotHand $h): string
  {
    $game = $this->gameStrain($h);

    return $game !== null && $game !== 'NT' ? $game : ($this->minorFit($h) ?? $h->longest(RobotHand::SUITS, 6) ?? 'NT');
  }

  /**
   * A bid's level, 8 for none (no bid in that strain is left).
   */
  private static function levelOf(?string $call): int
  {
    return $call === null ? 8 : AuctionView::level($call);
  }

  private function between(RobotHand $h, int $min, int $max): bool
  {
    return $h->hcp() >= $min && $h->hcp() <= $max;
  }
}

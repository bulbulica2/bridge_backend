<?php

namespace Tests\Unit\Robots;

use App\auxiliary\Seats;
use App\Robots\AuctionView;
use App\Robots\RobotBidder;
use App\Robots\RobotHand;
use App\Services\AuctionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The robots' bidding (`docs/ROBOTS.md`), over in-memory hands: no
 * database. Hands are spades.hearts.diamonds.clubs; calls are made in turn
 * from dealer N, and the robot is the next seat to call.
 */
class RobotBidderTest extends TestCase
{
  use MakesCards;

  /**
   * calls so far, the robot's hand, its call
   */
  public static function openings(): array
  {
    return [
      '1NT with 15-17 balanced' => [[], 'AKQ2.KJ3.Q42.J32', '1NT'],
      '2NT with 20-21 balanced' => [[], 'AKQ2.AKJ.Q42.J32', '2NT'],
      'a strong 2C with 22+' => [[], 'AKQ2.AKQJ.AQ2.32', '2C'],
      'a five-card major' => [[], 'AKJ32.K32.Q2.432', '1S'],
      'spades with 5-5 in the majors' => [[], 'AK432.KQ432.2.2', '1S'],
      'diamonds with 4-4 in the minors' => [['P'], 'AK2.K3.Q432.J432', '1D'],
      'clubs with 3-3 in the minors' => [['P', 'P'], 'AK32.K32.Q32.J32', '1C'],
      'a weak two' => [[], 'KQJ932.32.432.32', '2S'],
      'a weak two in diamonds' => [['P'], '32.32.AQT932.432', '2D'],
      'no weak two with a four-card side major' => [[], 'KQJ932.Q432.32.2', 'P'],
      'no weak two in a poor suit' => [[], 'J98765.K32.K32.2', 'P'],
      'a three-level preempt with seven' => [[], '2.32.KQJT932.432', '3D'],
      'no preempt in fourth seat' => [['P', 'P', 'P'], '2.32.KQJT932.432', 'P'],
      'under 12 passes' => [['P', 'P', 'P'], 'AK32.K32.432.432', 'P'],
      'board 10: 1H with seven hearts and two aces' => [[], 'Q.AT76532.65.A64', '1H'],
      'board 10: West passes, eight losers' => [['P', 'P'], 'AJ984.KJ4.42.QT2', 'P'],
      'board 10: North passes, 11 balanced' => [['P', 'P', 'P'], 'K652.Q8.KT98.K75', 'P'],
      'a light opening: 10, a six-card major, two quick tricks' => [[], 'AQ9876.A32.54.32', '1S'],
      'a light opening in a minor' => [[], '32.A2.32.AQ98765', '1C'],
      'Q-J-10 is a trick: a light opening' => [[], 'QJT.AK8765.32.32', '1H'],
      'Q-x-x is not: a weak two instead' => [[], 'Q32.AK8765.32.J2', '2H'],
      'a singleton queen is a loser: pass' => [[], 'Q.KJ9876.K32.J32', 'P'],
      'no light opening on a five-card suit and 10' => [[], 'AK432.K432.32.32', 'P'],
      'no light opening balanced' => [[], 'AK32.K32.J32.432', 'P'],
      'no light opening without two quick tricks' => [[], 'KQJ876.K32.Q2.32', '2S'],
      'a seven-card good suit with 7 still preempts' => [[], '2.32.AKT9832.432', '3D'],
    ];
  }

  public static function noTrumpResponses(): array
  {
    return [
      'Stayman with a four-card major and 8+' => [['1NT', 'P'], 'KJ32.Q32.K32.432', '2C'],
      'Stayman with 5-4 in the majors' => [['1NT', 'P'], 'KJ432.QJ32.K2.32', '2C'],
      'a transfer to hearts, however weak' => [['1NT', 'P'], '32.KJ432.432.432', '2D'],
      'a transfer to spades with 5-5' => [['1NT', 'P'], 'KJ432.Q5432.32.2', '2H'],
      '2NT invites with 8-9' => [['1NT', 'P'], 'K2.Q32.Q432.J432', '2NT'],
      '3NT with 10-15' => [['1NT', 'P'], 'K2.K32.Q432.Q432', '3NT'],
      'a quantitative 4NT with 16-17' => [['1NT', 'P'], 'KQ2.AQ3.K432.Q32', '4NT'],
      'Gerber with slam values' => [['1NT', 'P'], 'AQ2.AQ3.KQ32.Q32', '4C'],
      'pass with 7 and no major' => [['1NT', 'P'], 'K2.Q32.Q432.5432', 'P'],
      'Stayman over 2NT' => [['2NT', 'P'], 'KJ32.Q32.432.432', '3C'],
      'a transfer over 2NT' => [['2NT', 'P'], '32.J5432.432.432', '3D'],
      '3NT over 2NT with 4+' => [['2NT', 'P'], 'K32.Q32.5432.432', '3NT'],
      'pass over 2NT under 4' => [['2NT', 'P'], 'J32.432.5432.432', 'P'],
      'systems on over a double' => [['1NT', 'X'], '32.KJ432.432.432', '2D'],
      '3NT over their overcall with a stopper' => [['1NT', '2S'], 'KJ2.Q32.K432.K32', '3NT'],
      'a penalty double of their overcall' => [['1NT', '2H'], '32.KJ32.K432.Q32', 'X'],
    ];
  }

  public static function noTrumpContinuations(): array
  {
    return [
      'Stayman answer: four hearts' => [['1NT', 'P', '2C', 'P'], 'AK2.KQ32.Q42.J32', '2H'],
      'Stayman answer: four spades' => [['1NT', 'P', '2C', 'P'], 'AKJ2.KQ3.Q42.J32', '2S'],
      'Stayman answer: no major' => [['1NT', 'P', '2C', 'P'], 'AK2.KQ3.Q432.J32', '2D'],
      'the transfer completed' => [['1NT', 'P', '2D', 'P'], 'AK2.K32.Q432.K32', '2H'],
      'a super-accept with 17 and four' => [['1NT', 'P', '2D', 'P'], 'AK2.KQ32.Q42.K32', '3H'],
      'game in the Stayman fit' => [['1NT', 'P', '2C', 'P', '2H', 'P'], 'K32.KJ32.K32.432', '4H'],
      'an invitation in the Stayman fit' => [['1NT', 'P', '2C', 'P', '2H', 'P'], 'K32.QJ32.Q32.432', '3H'],
      '3NT with no fit' => [['1NT', 'P', '2C', 'P', '2D', 'P'], 'KJ32.Q32.K32.K32', '3NT'],
      'opener picks spades over 3NT' => [['1NT', 'P', '2C', 'P', '2H', 'P', '3NT', 'P'], 'AQ32.KJ32.K2.Q32', '4S'],
      'game with a six-card major after the transfer' => [['1NT', 'P', '2D', 'P', '2H', 'P'], '2.KQJ432.AK2.432', '4H'],
      '2NT invites with five after the transfer' => [['1NT', 'P', '2D', 'P', '2H', 'P'], '32.KJ432.Q32.K32', '2NT'],
      '3NT offers a choice with five' => [['1NT', 'P', '2D', 'P', '2H', 'P'], 'K2.KJ432.Q32.K32', '3NT'],
      'weak: pass the transfer' => [['1NT', 'P', '2D', 'P', '2H', 'P'], '32.J5432.432.432', 'P'],
      'opener takes the major with three' => [['1NT', 'P', '2D', 'P', '2H', 'P', '3NT', 'P'], 'AK2.Q32.KQ32.Q32', '4H'],
      'the quantitative 4NT accepted with a maximum' => [['1NT', 'P', '4NT', 'P'], 'AK2.KQ3.Q432.Q32', '6NT'],
      'and declined with a minimum' => [['1NT', 'P', '4NT', 'P'], 'AK2.KQ3.Q432.J32', 'P'],
      'accept 2NT with 16-17' => [['1NT', 'P', '2NT', 'P'], 'AK2.KQ3.Q42.Q32', '3NT'],
      'decline it with 15' => [['1NT', 'P', '2NT', 'P'], 'AK2.KQ3.Q42.J32', 'P'],
    ];
  }

  public static function slams(): array
  {
    return [
      'Gerber: one ace' => [['1NT', 'P', '4C', 'P'], 'A32.KQ3.KQ32.QJ2', '4H'],
      'Gerber: none or four' => [['1NT', 'P', '4C', 'P'], 'KQ2.KQ3.KJ32.Q32', '4D'],
      '6NT with every ace after Gerber' => [['1NT', 'P', '4C', 'P', '4S', 'P'], 'AQ2.AQ3.KQ32.Q32', '6NT'],
      'Blackwood with slam values and a fit' => [['1H', 'P', '3H', 'P'], 'AK2.AKQ32.KQ2.Q2', '4NT'],
      'Blackwood: two aces' => [['1H', 'P', '3H', 'P', '4NT', 'P'], 'A32.KJ32.A32.432', '5H'],
      'a small slam missing no ace' => [['1H', 'P', '3H', 'P', '4NT', 'P', '5H', 'P'], 'AK2.AKQ32.KQ2.Q2', '6H'],
      'sign off missing two aces' => [['1H', 'P', '3H', 'P', '4NT', 'P', '5C', 'P'], 'AQ2.KQJ32.KQ2.K2', '5H'],
      'nothing after the sign-off' => [['1H', 'P', '3H', 'P', '4NT', 'P', '5C', 'P', '5H', 'P'], 'K32.KJ32.K32.432', 'P'],
    ];
  }

  public static function strongTwoClubs(): array
  {
    return [
      '2D waiting, whatever the hand' => [['2C', 'P'], '432.432.5432.432', '2D'],
      '2NT with 22-24 balanced' => [['2C', 'P', '2D', 'P'], 'AKQ2.AKJ.KQ2.J32', '2NT'],
      'the long suit, forcing to game' => [['2C', 'P', '2D', 'P'], 'AKQJ32.AK2.AQ2.2', '2S'],
      'a raise with support and 8+' => [['2C', 'P', '2D', 'P', '2S', 'P'], 'K32.Q32.K432.Q32', '3S'],
      'game with support and less' => [['2C', 'P', '2D', 'P', '2S', 'P'], 'J32.Q32.5432.432', '4S'],
      '2NT with nothing' => [['2C', 'P', '2D', 'P', '2S', 'P'], '32.Q32.5432.J432', '2NT'],
      'Stayman over the 2NT rebid' => [['2C', 'P', '2D', 'P', '2NT', 'P'], 'KJ32.Q32.432.432', '3C'],
    ];
  }

  public static function preempts(): array
  {
    return [
      'game over a weak two with 16+' => [['2S', 'P'], 'A32.K32.AK32.K32', '4S'],
      'a preemptive raise with three' => [['2S', 'P'], 'Q32.K432.Q432.32', '3S'],
      '3NT over a weak 2D, balanced and stopped' => [['2D', 'P'], 'AK2.KQ2.32.AJ432', '3NT'],
      'otherwise pass the weak two' => [['2H', 'P'], 'KQ32.2.Q5432.J32', 'P'],
      'game over a preempt with 16+' => [['3H', 'P'], 'AK2.K2.AK32.Q432', '4H'],
      'the weak two opener passes the raise' => [['2S', 'P', '3S', 'P'], 'KQJ932.32.432.32', 'P'],
    ];
  }

  public static function overcalls(): array
  {
    return [
      'a five-card suit at the 1 level with 8+' => [['1D'], 'KQJ32.K32.32.432', '1S'],
      'not at the 2 level under 11' => [['1S'], 'K32.KQJ32.32.432', 'P'],
      'at the 2 level with 11+' => [['1S'], 'K32.AQJ32.K2.432', '2H'],
      '1NT with 15-18 balanced and a stopper' => [['1H'], 'KJ2.A32.KQ32.K32', '1NT'],
      'no 1NT without a stopper in their suit' => [['1H'], 'KJ2.432.AKQ2.KQ2', 'X'],
      'never in their suit' => [['1S'], 'AKQ32.K32.32.432', 'P'],
      'a takeout double: short in theirs, support for the rest' => [['1H'], 'KQ32.2.AJ32.K432', 'X'],
      'a double first with 18+' => [['1H'], 'AKQJ32.A2.K32.K2', 'X'],
      'a weak jump overcall' => [['1D'], 'KQJ932.32.432.32', '2S'],
      'a penalty double of their 1NT' => [['1NT'], 'AK2.KQ32.KJ2.Q32', 'X'],
      'balancing: 1NT with 11-14' => [['1H', 'P', 'P'], 'KJ2.A32.Q432.K32', '1NT'],
      'balancing: a double with 9+' => [['1H', 'P', 'P'], 'KJ32.2.Q432.K432', 'X'],
      'balancing: a suit with 6+' => [['1H', 'P', 'P'], 'KJ432.32.Q432.32', '1S'],
      'but not directly' => [['1H'], 'KJ432.32.Q432.32', 'P'],
    ];
  }

  public static function advances(): array
  {
    return [
      'the unbid major after a takeout double' => [['1D', 'X', 'P'], 'Q432.J32.432.432', '1S'],
      'a jump with 9-11' => [['1D', 'X', 'P'], 'KQ32.J32.K32.432', '2S'],
      'game with 12+' => [['1D', 'X', 'P'], 'KQ32.K32.K32.A32', '4S'],
      '1NT with their suit stopped' => [['1D', 'X', 'P'], 'K32.J32.KJ32.Q32', '1NT'],
      'a penalty pass with their suit' => [['1D', 'X', 'P'], '32.K2.KQJ98.Q432', 'P'],
      'free to pass when they bid' => [['1D', 'X', '1S'], '432.J32.432.K432', 'P'],
      'a raise of partner\'s overcall' => [['1C', '1S', 'P'], 'K32.Q432.K432.32', '2S'],
      'a new suit over partner\'s overcall' => [['1C', '1H', 'P'], 'KQ432.32.K432.32', '1S'],
      'a jump raise with 11-13' => [['1C', '1S', 'P'], 'K32.AQ32.K432.32', '3S'],
    ];
  }

  public static function responses(): array
  {
    return [
      '2M with 6-10 and support' => [['1H', 'P'], 'K32.Q432.J432.32', '2H'],
      'a limit raise with 11-12' => [['1H', 'P'], 'K32.KQ32.K432.32', '3H'],
      '4M with 13+' => [['1H', 'P'], 'A32.KQ32.K432.Q2', '4H'],
      'hearts first with 4-4 majors over a minor' => [['1D', 'P'], 'KJ32.Q432.32.432', '1H'],
      'a five-card major at the 1 level' => [['1C', 'P'], 'KJ432.Q4.32.5432', '1S'],
      'a jump shift with 19+ and a five-card suit' => [['1C', 'P'], 'AKQ32.AK2.K32.32', '2S'],
      '1NT with 6-10 and nothing to show' => [['1S', 'P'], 'K2.Q432.J432.432', '1NT'],
      '2NT with 13-15 balanced' => [['1S', 'P'], 'K2.AQ432.KJ2.432', '2NT'],
      'a 2-level new suit with 11+' => [['1S', 'P'], 'K2.AQ432.KJ32.43', '2H'],
      'a raise of a minor' => [['1D', 'P'], '32.432.KJ32.K432', '2D'],
      'a limit raise of a minor' => [['1D', 'P'], '2.K32.KQ432.K43', '3D'],
      'pass under 6' => [['1S', 'P'], 'Q2.J432.5432.432', 'P'],
      'a negative double showing the unbid major' => [['1D', '1S'], 'K2.Q432.J432.432', 'X'],
      'a negative double showing both majors' => [['1C', '1D'], 'KJ32.Q432.32.432', 'X'],
      'a five-card major at the 1 level instead' => [['1C', '1D'], 'KJ432.Q432.3.432', '1S'],
      'a raise still made over an overcall' => [['1H', '1S'], 'K32.Q432.J432.32', '2H'],
      '1NT over an overcall with it stopped' => [['1C', '1S'], 'KJ2.Q32.K432.432', '1NT'],
      'a penalty double of their 1NT overcall' => [['1D', '1NT'], 'KQ2.Q432.K32.J32', 'X'],
      'a bid the overcall made illegal is skipped' => [['1S', '3H'], 'K2.Q432.J432.432', 'P'],
    ];
  }

  public static function rebids(): array
  {
    return [
      'raise the response with four' => [['1C', 'P', '1H', 'P'], 'A2.KJ32.A2.K5432', '2H'],
      'a jump raise with 16-18' => [['1C', 'P', '1H', 'P'], 'A2.KQJ2.A2.K5432', '3H'],
      'game with 19+' => [['1C', 'P', '1H', 'P'], 'A2.AQJ2.AK2.K543', '4H'],
      'a major at the 1 level before 1NT' => [['1C', 'P', '1D', 'P'], 'K32.AJ32.Q2.K432', '1H'],
      '1NT with 12-14 balanced' => [['1D', 'P', '1S', 'P'], 'K2.AJ3.Q432.K432', '1NT'],
      'a six-card suit again' => [['1H', 'P', '2C', 'P'], 'A2.KQJ432.K32.32', '2H'],
      '2NT with 12-14 after a 2-level response' => [['1S', 'P', '2D', 'P'], 'AK432.Q32.K32.32', '2NT'],
      'a new lower suit at the 2 level' => [['1D', 'P', '1H', 'P'], 'A2.32.AK432.KQ32', '2C'],
      'a new suit at the 1 level' => [['1C', 'P', '1H', 'P'], 'KQ32.2.A32.KJ432', '1S'],
      'no reverse under 17' => [['1D', 'P', '1S', 'P'], '32.KQ32.AK432.32', '2D'],
      'a reverse with 17+' => [['1D', 'P', '1S', 'P'], '2.AKQ2.AK432.Q32', '2H'],
      'a jump shift with 19+' => [['1D', 'P', '1S', 'P'], 'A2.AKQ2.AKJ32.32', '3H'],
      'the unbid major over a negative double' => [['1C', '1S', 'X', 'P'], 'K2.KJ32.A32.K432', '2H'],
      'a penalty pass of the negative double' => [['1D', '1S', 'X', 'P'], 'KQJ3.32.AK32.432', 'P'],
    ];
  }

  public static function laterBids(): array
  {
    return [
      'invite with 15-18 opposite a single raise' => [['1H', 'P', '2H', 'P'], 'A2.AKJ32.KQ32.32', '3H'],
      'game with 19+ opposite a single raise' => [['1H', 'P', '2H', 'P'], 'A2.AKJ32.KQ2.AQ2', '4H'],
      'pass a single raise with a minimum' => [['1H', 'P', '2H', 'P'], 'A2.KJ432.K32.Q32', 'P'],
      'accept an invitation with the top half' => [['1H', 'P', '2H', 'P', '3H', 'P'], 'K32.Q432.K432.32', '4H'],
      'decline it with the bottom half' => [['1H', 'P', '2H', 'P', '3H', 'P'], 'K32.Q432.J432.32', 'P'],
      'pass 1NT with a minimum' => [['1S', 'P', '1NT', 'P'], 'AK432.Q32.K32.32', 'P'],
      'game in 3NT opposite a 1NT rebid with 13+' => [['1C', 'P', '1H', 'P', '1NT', 'P'], 'K32.AQ432.K32.Q2', '3NT'],
      'game in the fit opposite a raise with 15+' => [['1C', 'P', '1H', 'P', '2H', 'P'], 'K32.AQ432.K32.K2', '4H'],
      'an invitation with 14: the raise may be a light opener' => [['1C', 'P', '1H', 'P', '2H', 'P'], 'K32.AQ432.K32.Q2', '3H'],
      'the partner of a 6-card rebid finds the fit' => [['1S', 'P', '1NT', 'P', '2S', 'P', '3S', 'P'], 'AK9654.K.QT4.K96', '4S'],
      'fourth suit forcing: game values, no fit, no stopper' => [['1D', 'P', '1H', 'P', '1S', 'P'], 'A32.AQ432.KQ2.32', '2C'],
      'fourth suit answered with a stopper' => [['1D', 'P', '1H', 'P', '1S', 'P', '2C', 'P'], 'KQ32.K2.AJ32.Q32', '2NT'],
      'a reverse forces a bid' => [['1D', 'P', '1S', 'P', '2H', 'P'], 'KJ432.32.32.5432', '2NT'],
      'nothing after game' => [['1H', 'P', '4H', 'P'], 'A2.AKJ32.KQ2.Q32', 'P'],
    ];
  }

  public static function competition(): array
  {
    return [
      'game over interference when it is sure' => [['1H', 'P', '2H', '3C'], 'A2.AKJ32.KQ2.AQ2', '4H'],
      'compete to the 3 level with nine trumps' => [['1H', 'P', '2H', '2S'], 'K2.AQJ432.K32.32', '3H'],
      'but not with eight' => [['1H', 'P', '2H', '2S'], 'K2.AQJ43.K32.432', 'P'],
      'a penalty double of a low contract' => [['1H', 'P', '1S', '2C'], 'K2.AQJ43.2.KQJ32', 'X'],
      'the takeout doubler\'s partner competes when free' => [['1H', 'X', '2H'], 'KQ432.32.K432.32', '2S'],
    ];
  }

  /**
   * No trump with the opponents in the auction: their suits stopped (by
   * this hand, or promised by partner's no trump), and a hand for it.
   */
  public static function stoppers(): array
  {
    return [
      'board 4: 2C, not 2NT, with a singleton and six clubs' => [['1D', '1S'], 'Q64.8.K76.KQJT65', '2C'],
      'no 1NT over their 1H with xx in hearts' => [['1D', '1H'], 'K32.32.Q432.KJ32', '2D'],
      '1NT over their 1H with Kx in hearts' => [['1D', '1H'], 'Q32.K2.Q432.K32', '1NT'],
      '2NT over an overcall, balanced and stopped' => [['1D', '1S'], 'Q64.K8.K76.KJ765', '2NT'],
      '3NT over an overcall with a good six-card minor' => [['1D', '1S'], 'A64.K8.76.AKJ765', '3NT'],
      'no 1NT advancing a double with a singleton' => [['1D', 'X', 'P'], 'K32.J.KJ32.Q5432', '3C'],
      'opener rebids 2NT with their suit stopped' => [['1D', '1S', '2C', 'P'], 'Q32.AK3.KJ32.J32', '2NT'],
      'and not without' => [['1D', '1S', '2C', 'P'], 'J32.AK3.KJ32.Q32', '3C'],
      'game in 3NT over their bid with it stopped' => [['1D', '1S', '2C', '2S'], 'KJ2.AQ3.AQ32.K32', '3NT'],
      'five of the minor fit without' => [['1D', '1S', '2C', '2S'], 'Q2.AK3.AQ32.K432', '5C'],
      'pass with no stopper and no fit' => [['1D', '1S', '2C', '2S'], 'Q2.AK32.AQ32.K32', 'P'],
      'the 2NT invitation with it stopped' => [['1D', '1S', '2C', 'P', '2D', 'P'], 'Q64.8.K76.KQJT65', '2NT'],
      'no invitation without' => [['1D', '1S', '2C', 'P', '2D', 'P'], '864.Q8.K76.KQJT6', 'P'],
      'a reverse answered in no trump with it stopped' => [['1C', '1D', '1S', 'P', '2H', 'P'], 'KJ432.32.Q32.432', '2NT'],
      'and in partner\'s suit without' => [['1C', '1D', '1S', 'P', '2H', 'P'], 'KJ432.32.432.Q32', '3C'],
      'partner\'s 1NT has stopped their suit' => [['1D', '1S', '1NT', 'P'], '32.AK2.AQ432.KQ2', '2NT'],
      'the Blackwood sign-off in no trump with it stopped' => [['1D', '1S', '2C', 'P', '3C', 'P', '4NT', 'P', '5H', 'P'], 'KQ2.KQ.KQ2.KJ432', '5NT'],
      'and in the minor fit without, even at six' => [['1D', '1S', '2C', 'P', '3C', 'P', '4NT', 'P', '5H', 'P'], '432.KQ.KQ2.KQJ32', '6C'],
    ];
  }

  #[DataProvider('openings')]
  #[DataProvider('noTrumpResponses')]
  #[DataProvider('noTrumpContinuations')]
  #[DataProvider('slams')]
  #[DataProvider('strongTwoClubs')]
  #[DataProvider('preempts')]
  #[DataProvider('overcalls')]
  #[DataProvider('advances')]
  #[DataProvider('responses')]
  #[DataProvider('rebids')]
  #[DataProvider('laterBids')]
  #[DataProvider('competition')]
  #[DataProvider('stoppers')]
  public function test_the_call(array $names, string $hand, string $expected): void
  {
    $calls = $this->calls('N', $names);
    $seat = $calls === [] ? 'N' : Seats::next(end($calls)['seat']);

    $this->assertSame($expected, RobotBidder::choose($this->hand($hand), $calls, $seat));
  }

  /**
   * calls, the call to explain (the last one), its explanation
   */
  public static function explanations(): array
  {
    return [
      'an opening' => [['1NT'], 'Opening: 15–17 HCP, balanced'],
      'one of a suit, light openings included' => [['1H'], 'Opening: 10–21 HCP, 5+ ♥'],
      'Stayman' => [['1NT', 'P', '2C'], 'Stayman: 8–17 HCP, asks for a four-card major'],
      'a transfer' => [['1NT', 'P', '2D'], 'Transfer: 0–17 HCP, 5+ ♥, asks partner to bid ♥'],
      'a weak two' => [['2S'], 'Weak two: 5–11 HCP, 6+ ♠'],
      'a takeout double' => [['1H', 'X'], 'Takeout double: 12+ HCP, short in ♥, asks partner to pick a suit'],
      'a negative double' => [['1C', '1S', 'X'], 'Negative double: 6+ HCP, 4+ ♥'],
      'an answer to Blackwood' => [['1H', 'P', '3H', 'P', '4NT', 'P', '5D'], 'Aces: one ace'],
      'a jump shift' => [['1C', 'P', '2S'], 'Jump shift: 19+ HCP, 4+ ♠, forcing to game'],
      'no trump over their suit' => [['1D', '1S', '2NT'], 'Invitation: 11–12 HCP, balanced, ♠ stopped, invites game'],
      'a call the system doesn\'t make' => [['5C'], 'Natural'],
    ];
  }

  #[DataProvider('explanations')]
  public function test_every_call_has_an_explanation(array $names, string $explanation): void
  {
    $meanings = RobotBidder::read($this->calls('N', $names));

    $this->assertSame($explanation, end($meanings)->explanation());
  }

  /**
   * calls, whether the last one is alerted
   */
  public static function alerts(): array
  {
    return [
      'Stayman' => [['1NT', 'P', '2C'], true],
      'a transfer' => [['1NT', 'P', '2H'], true],
      'a super-accept' => [['1NT', 'P', '2D', 'P', '3H'], true],
      'the strong 2♣' => [['2C'], true],
      'the waiting 2♦' => [['2C', 'P', '2D'], true],
      'Gerber' => [['1NT', 'P', '4C'], true],
      'Blackwood' => [['1H', 'P', '3H', 'P', '4NT'], true],
      'an answer to Blackwood' => [['1H', 'P', '3H', 'P', '4NT', 'P', '5D'], true],
      'fourth suit forcing' => [['1C', 'P', '1H', 'P', '1S', 'P', '2D'], true],
      'a negative double' => [['1C', '1S', 'X'], true],
      'a penalty double of their 1NT' => [['1NT', 'X'], true],
      'a natural opening' => [['1NT'], false],
      'a natural raise' => [['1H', 'P', '2H'], false],
      'the answer to Stayman' => [['1NT', 'P', '2C', 'P', '2D'], false],
      'completing a transfer' => [['1NT', 'P', '2D', 'P', '2H'], false],
      'a takeout double' => [['1H', 'X'], false],
      'a pass' => [['P'], false],
      'a call the system doesn\'t make' => [['5C'], false],
    ];
  }

  #[DataProvider('alerts')]
  public function test_only_conventional_calls_are_alerted(array $names, bool $alerted): void
  {
    $meanings = RobotBidder::read($this->calls('N', $names));

    $this->assertSame($alerted, end($meanings)->alert);
  }

  public function test_a_robot_alerts_its_convention_with_its_explanation(): void
  {
    $bid = RobotBidder::bid($this->hand('AKQ2.AKQ2.AK2.A2'), [], 'N');

    $this->assertSame('2C', $bid['call']);
    $this->assertTrue($bid['alert']);
    $this->assertSame('Strong 2♣: 22+ HCP, artificial, forcing', $bid['explanation']);

    $this->assertFalse(RobotBidder::bid($this->hand('AK2.KQ2.A432.432'), [], 'N')['alert']);
  }

  public function test_a_robot_explains_its_own_call(): void
  {
    $bid = RobotBidder::bid($this->hand('32.KJ432.432.432'), $this->calls('N', ['1NT', 'P']), 'S');

    $this->assertSame('2D', $bid['call']);
    $this->assertSame('Transfer: 0–17 HCP, 5+ ♥, asks partner to bid ♥', $bid['explanation']);
    $this->assertTrue($bid['rule']);
  }

  public function test_partner_is_read_back_through_the_whole_auction(): void
  {
    $calls = $this->calls('N', ['1H', 'P', '2H', 'P', '3H', 'P']);

    $opener = RobotBidder::shown($calls, 'N');
    $responder = RobotBidder::shown($calls, 'S');

    // 1H (12–21, five hearts), then 3H: an invitation opposite 6–10
    $this->assertSame([15, 18, 5], [$opener['min'], $opener['max'], $opener['lengths']['H']]);
    $this->assertTrue($opener['invite']);
    $this->assertSame([6, 10, 3], [$responder['min'], $responder['max'], $responder['lengths']['H']]);
    $this->assertFalse($responder['invite']);

    $this->assertFalse(RobotBidder::shown($calls, 'E')['known']);
  }

  public function test_answers_to_conventions_add_to_the_picture(): void
  {
    $calls = $this->calls('N', ['1NT', 'P', '2C', 'P', '2H', 'P']);
    $opener = RobotBidder::shown($calls, 'N');

    $this->assertSame([15, 17, 4], [$opener['min'], $opener['max'], $opener['lengths']['H']]);
    $this->assertTrue($opener['balanced']);

    // a transfer shows the major, not the suit bid
    $transfer = RobotBidder::shown($this->calls('N', ['1NT', 'P', '2D']), 'S');
    $this->assertSame([5, 0], [$transfer['lengths']['H'], $transfer['lengths']['D']]);
  }

  public function test_a_natural_no_trump_promises_their_suits_stopped(): void
  {
    $this->assertSame(['S'], RobotBidder::shown($this->calls('N', ['1D', '1S', '1NT']), 'S')['stopped']);
    $this->assertSame([], RobotBidder::shown($this->calls('N', ['1NT']), 'N')['stopped']);
  }

  public function test_their_suits_are_the_ones_they_bid_naturally(): void
  {
    $view = fn (array $names, string $seat) => new AuctionView($this->calls('N', $names), RobotBidder::read($this->calls('N', $names)), $seat);

    $this->assertSame(['S'], $view(['1H', '2S'], 'S')->theirSuits());
    $this->assertSame([], $view(['1H', '2H'], 'S')->theirSuits(), 'a cue bid of our suit');
    $this->assertSame([], $view(['1H', 'X'], 'S')->theirSuits(), 'a takeout double');
    $this->assertSame([], $view(['1NT', 'P', '2C'], 'W')->theirSuits(), 'Stayman');
    $this->assertSame(['H'], $view(['1NT', 'P', '2D'], 'W')->theirSuits(), 'a transfer shows the major');
  }

  public function test_legal_calls(): void
  {
    $view = fn (array $names, string $seat) => new AuctionView($this->calls('N', $names), RobotBidder::read($this->calls('N', $names)), $seat);

    $this->assertSame('1C', $view([], 'N')->cheapest('C'));
    $this->assertSame('1S', $view(['1H'], 'E')->cheapest('S'));
    $this->assertSame('2H', $view(['1S', 'X'], 'S')->cheapest('H'));
    $this->assertSame('3H', $view(['1S', 'X'], 'S')->jump('H'));
    $this->assertNull($view(['7NT'], 'E')->cheapest('C'));

    $this->assertTrue($view(['1H'], 'E')->isLegal('X'));
    $this->assertFalse($view(['1H', 'P'], 'S')->isLegal('X'), 'partner\'s bid');
    $this->assertFalse($view(['1H', 'X'], 'S')->isLegal('X'), 'already doubled');
    $this->assertTrue($view(['1H', 'X'], 'S')->isLegal('XX'));
    $this->assertFalse($view(['1H', 'X', 'P'], 'W')->isLegal('XX'), 'their own double');
    $this->assertFalse($view(['1H'], 'E')->isLegal('1C'));
  }

  /**
   * Four robots bid a few hundred random deals: every call is legal by the
   * real rules, never a redouble, every auction ends, and every call a
   * rule made fits the high-card points its own meaning shows.
   */
  public function test_every_call_is_legal_and_every_auction_ends(): void
  {
    for ($board = 0; $board < 500; $board++) {
      $hands = array_map(fn ($cards) => new RobotHand($cards), $this->deal());
      $dealer = Seats::SEATS[$board % 4];
      $calls = [];
      $made = [];

      while (! AuctionService::isOver($made)) {
        $this->assertLessThan(60, count($calls), 'the auction never ends');

        $seat = AuctionService::nextToCall($made, $dealer);
        $bid = RobotBidder::bid($hands[$seat], $calls, $seat);
        $call = $bid['call'];
        $context = "$call by $seat after ".json_encode(array_column($calls, 'call')).' holding '.$this->written($hands[$seat]);

        $this->assertNotSame('XX', $call);
        $this->assertNull(AuctionService::illegalReason($made, $seat, $this->bid($call)), $context);

        if ($bid['rule']) {
          $hcp = $hands[$seat]->hcp();
          $this->assertGreaterThanOrEqual($bid['meaning']->min ?? 0, $hcp, "$context: {$bid['explanation']}");
          $this->assertLessThanOrEqual($bid['meaning']->max ?? 40, $hcp, "$context: {$bid['explanation']}");
        }

        $calls[] = ['seat' => $seat, 'call' => $call];
        $made[] = ['seat' => $seat, 'bid' => $this->bid($call)];
      }
    }
  }

  /**
   * A hand written back as spades.hearts.diamonds.clubs, for messages.
   */
  private function written(RobotHand $hand): string
  {
    $names = [15 => 'A', 14 => 'K', 13 => 'Q', 12 => 'J', 10 => 'T'];

    return implode('.', array_map(
      fn ($suit) => implode('', array_map(fn ($card) => $names[$card['rank']] ?? (string) $card['rank'], $hand->cards($suit))) ?: '-',
      RobotHand::SUITS,
    ));
  }
}

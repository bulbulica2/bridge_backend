# Robots

A **robot** is a computer player that takes a seat nobody else is in, so one
person can play (or test) the game alone. This page is the complete list of
what a robot does and how it decides: when it acts, what it can see, every
call it can make, how it leads and follows, and when it accepts a claim.

It describes the code as it is ("v1"): `app/Robots/` and
`App\Services\RobotService`. Better bidding and better card play are
separate follow-up issues. For the endpoints see [`API.md`](API.md#tables);
for the bridge terms see [`GAME-RULES.md`](GAME-RULES.md) (§9 is the short
version of this page).

## Contents

- [At the table](#at-the-table)
- [Bidding](#bidding)
- [Card play](#card-play)
- [Claims](#claims)
- [The next board](#the-next-board)
- [What robots don't do](#what-robots-dont-do)
- [Code map](#code-map)

## At the table

**Getting robots.** `POST /tables` with `{"robots": true}` puts a robot in
each of the three seats the creator didn't take, which deals the first board
at once. A table manager can also fill any free seat:
`POST /tables/{table}/seats/robots {"seat": "E"}`. Robots are `users` rows
with `is_robot: true`, named `Robot <n>` / `robot-<n>`, from a pool that
reuses idle robots and makes a new one when all are busy. They can't log in.

**When they act.** Only while **at least one human** sits at the table.
After every change to the game (every `PlayingUpdated`), and
`BRIDGE_ROBOT_DELAY_SECONDS` (1 s) later so a human can follow, **one**
robot makes **one** move if it is a robot's turn:

| Phase | The robot that moves | Its move |
|---|---|---|
| auction | the robot whose turn it is | one call ([Bidding](#bidding)) |
| play, no claim pending | the robot acting for `turn` — declarer's robot also plays dummy's cards | one card ([Card play](#card-play)) |
| play, claim pending | the first robot (N, E, S, W order) that still has to answer | accept or reject ([Claims](#claims)) |
| finished | the first robot not yet ready for the next board | ready ([The next board](#the-next-board)) |

That move changes the game, which sends the next `PlayingUpdated`, so the
robots take their turns one after another until it is a human's turn. A
robot that is dummy never acts: declarer plays dummy's cards.

**The same rules as a human.** Every robot move goes through the services a
human's request goes through (`AuctionService`, `CardPlayService`,
`ClaimService`, `BoardSelectionService::moveOn()`), so it is checked by the
same rules and broadcast the same way.

**What a robot sees.** Exactly what a human in its seat would: the game state
`GET /tables/{table}/playing` serves that seat (`PlayingStateService::stateFor()`)
— its own hand, the auction, the cards played, dummy once face up and a
claimer's hand while a claim is pending. Never the other hands.

**When people leave.** A human who leaves frees their seat; no robot takes
it. When the **last** human leaves, the table is kept but *unattended*: the
robots stop, anyone may kick them, the first human to sit down becomes the
table's moderator, and after `BRIDGE_UNATTENDED_TABLE_MINUTES` (10) with no
human the table is deleted. A robot is never the moderator.

## Bidding

A robot bids a small core of **SAYC** (Standard American Yellow Card):
five-card majors, a 15–17 1NT, and game at 25 combined points. **Anything
not listed here is a pass.** Robots never double or redouble. If the call the
rules below pick is not legal any more (an opponent bid higher meanwhile),
the robot passes.

### Hand evaluation

- **HCP** (high-card points): ace 4, king 3, queen 2, jack 1. Nothing is
  added for length or shortness.
- **Balanced**: no void, no singleton, at most one doubleton — 4-3-3-3,
  4-4-3-2 or 5-3-3-2.
- **Stopper** in a suit (for no trump): A, K-x, Q-x-x or J-x-x-x.
- **Longest suit** among some suits: the one with the most cards; on a tie,
  the higher-ranking (♠ > ♥ > ♦ > ♣).

### 1. Opening (nobody has bid yet)

Checked in this order:

| Hand | Call |
|---|---|
| 20–21 HCP, balanced | **2NT** |
| 15–17 HCP, balanced | **1NT** |
| under 12 HCP | Pass |
| 12+ HCP with a five-card major | **1♠** or **1♥**, the longer; **1♠** with 5-5 |
| 12+ HCP, no five-card major, more diamonds than clubs | **1♦** |
| … more clubs than diamonds | **1♣** |
| … equal minors, four or more each | **1♦** |
| … equal minors, three each | **1♣** |

So 12–14 and 18–19 balanced open one of a suit, and so does 22+ (there is no
2♣). There are no weak twos or preempts: under 12 HCP a robot always passes,
however long its suit.

### 2. Overcall (the opponents opened, our side hasn't bid)

| Hand | Call |
|---|---|
| 15–18 HCP, balanced, a stopper in **every** suit the opponents bid, and 1NT is still legal | **1NT** |
| a five-card suit the opponents haven't bid (the longest; higher on a tie), whose cheapest bid is at the **1 level**, with **8+ HCP** | that bid |
| … at the **2 level**, with **11+ HCP** | that bid |
| anything else | Pass |

No takeout doubles, no jump overcalls, no three-level overcalls, nothing
over partner's overcall (the overcaller's partner passes).

### 3. Responding to partner's opening (our first bid)

Also used when an opponent overcalled in between; a response the overcall
made illegal becomes a pass.

**Partner opened 1NT** (no Stayman, no transfers):

| Hand | Call |
|---|---|
| a six-card major and 8+ HCP | **4 of that major** (spades if both) |
| 10+ HCP | **3NT** |
| 8–9 HCP | **2NT** (invites 3NT) |
| under 8 HCP | Pass |

**Partner opened 2NT:** under 4 HCP pass; with a six-card major **4 of it**;
otherwise **3NT**.

**Partner opened one of a suit**, checked in this order:

| Hand | Call |
|---|---|
| partner opened a **major**, 3+ cards in it, 6–10 HCP | **2 of the major** |
| … 11–12 HCP | **3 of the major** (invites game) |
| … 13+ HCP | **4 of the major** |
| under 6 HCP | Pass |
| a four-card or longer **major** that can still be bid at the 1 level | **1♥** with four of each major; otherwise the longer (**1♠** with 5-5) |
| balanced, 13–15 HCP | **2NT** |
| balanced, 16+ HCP | **3NT** |
| 11+ HCP and a new four-card suit whose cheapest bid is at the 1 or 2 level | that suit (the longest; higher on a tie) — e.g. 1♠–2♥, 1♣–1♦ |
| anything else (6+ HCP) | **1NT** |

There are no minor-suit raises and no jump shifts: with support for partner's
minor and nothing else to say, a robot bids 1NT. Partner's opening at the 2
level or higher (a human's weak two, say) gets a pass.

### 4. Opener's rebid (we opened one of a suit, partner answered in a new suit)

Partner's new suit is forcing, so **this never passes**. It applies only if
nobody has bid since partner's response; otherwise the robot goes to
[placing the contract](#5-placing-the-contract-every-later-bid). Checked in
this order:

| Hand | Call |
|---|---|
| partner bid a **major** and we hold 4+ of it, 12–15 HCP | the cheapest raise (1♣–1♥–**2♥**) |
| … 16–18 HCP | a jump raise (1♣–1♥–**3♥**) |
| … 19+ HCP | **4 of the major** |
| balanced, partner answered at the 1 level, 12–14 HCP | **1NT** |
| … 18–19 HCP | **2NT** |
| balanced, partner answered at the 2 level, 12–14 HCP | **2NT** |
| … 15+ HCP | **3NT** |
| a six-card or longer suit of our own, 12–15 HCP | our suit at the cheapest level (1♥–2♣–**2♥**) |
| … 16+ HCP (or 19+ in a minor) | our suit, one level higher (a jump) |
| … 19+ HCP in a major | **4 of our major** |
| a new four-card suit bid at the **1 level**, or at the **2 level** if it ranks **below** our first suit — or above it (a *reverse*) with 17+ HCP | that suit (the longest; higher on a tie) — 1♣–1♥–**1♠**, 1♦–1♥–**2♣** |
| a five-card suit of our own | our suit at the cheapest level (1♦–1♠–**2♦**) |
| 1NT is still legal | **1NT** |
| three cards in partner's suit | the cheapest raise |
| anything else | our suit at the cheapest level |

A balanced 15–17 would have opened 1NT, so it is not in the table: such a
hand (a 5-3-3-2 with a five-card major, say) falls through to the later
rows.

### 5. Placing the contract (every later bid)

Once both partners have bid, the robot adds its HCP to the range partner has
shown (next section) and places the contract. It **passes** if:

- the opponents made the last bid (robots don't compete further);
- our contract is already **game** — 3NT, 4♥/4♠, 5♣/5♦ — or higher;
- partner's bids showed nothing the robot understands (a convention, a
  competitive bid).

Otherwise:

- **Fit**: a major in which our length plus partner's shown length is 8+.
  **Game** is 4 of that major, or 3NT without a fit.
- **Our HCP + partner's minimum ≥ 25** → bid **game**.
- **Our HCP + partner's maximum < 25** → **pass**.
- **In between** (game is possible):
  - partner's last bid was an **invitation** → accept (bid game) with our HCP
    at or above the middle of the range **we** have shown, else pass;
  - we already invited → pass (partner has answered it);
  - otherwise **invite**: 3 of the fit major, or **2NT** without a fit.

So robots never bid a slam and never bid above game. Examples:
1♥–2♥ (6–10): opener passes with 12–14, invites 3♥ with 15–18, bids 4♥ with
19+. 1♥–2♥–3♥: responder accepts 4♥ with 8–10, passes with 6–7.
1NT–2NT: opener bids 3NT with 16–17, passes with 15.

### What each bid is taken to show

This is how a robot reads its partner's bids (and its own, for "the middle
of the range we have shown"). A human partner who bids like a robot is
understood the same way.

| When | Bid | HCP | Suit length | Invites |
|---|---|---|---|---|
| Opening | 1NT | 15–17 | balanced | |
| | 2NT | 20–21 | balanced | |
| | 1♥ / 1♠ | 12–21 | 5+ | |
| | 1♣ / 1♦ | 12–21 | 3+ | |
| Overcall | 1NT | 15–18 | | |
| | a suit at the 1 level | 8–17 | 5+ | |
| | a suit at the 2 level or higher | 11–17 | 5+ | |
| Response to 1NT | 2NT | 8–9 | | yes |
| | 3NT | 10–17 | | |
| | 4♥ / 4♠ | 8–17 | 6+ | |
| Response to 2NT | 3NT, 4♥ / 4♠ | 4–11 | 6+ for the major | |
| Response to one of a suit | raise to 2 | 6–10 | 3+ | |
| | raise to 3 | 11–12 | 3+ | yes |
| | raise to 4 | 13–17 | 3+ | |
| | 1NT | 6–10 | | |
| | 2NT | 13–15 | | |
| | 3NT | 16–17 | | |
| | new suit at the 1 level | 6–17 | 4+ | |
| | new suit at the 2 level (not a jump) | 11–17 | 4+ | |
| Opener's rebid (after a new-suit response) | 1NT | 12–14 | | |
| | 2NT, over a 1-level response | 18–19 | | |
| | 2NT, over a 2-level response | 12–14 | | |
| | 3NT | 15–21 | | |
| | cheapest raise of partner | 12–15 | 4+ in partner's suit | |
| | jump raise of partner | 16–18 | 4+ | yes |
| | raise of partner to game | 19–21 | 4+ | |
| | own suit, cheapest | 12–15 | 5+ | |
| | own suit, jump | 16–18 | 6+ | yes |
| | own major, game | 19–21 | 6+ | |
| | new suit | 12–18 (a reverse 17–21) | 4+ | |
| Any other bid | | range unchanged | | yes, if it is 2NT or 3 of a major below game |

Any other opening or response (a jump shift, a weak two, Stayman …) tells a
robot nothing.

## Card play

### Words used here

- **Master card**: no card that could still be out beats it — every higher
  card of its suit has been played or is in a hand the robot's side can see.
  Declarer and dummy see both their hands; a defender sees only its own.
- **Equivalent cards**: two of our cards with nothing between them but
  cards played or held by our side (K-Q, or K-J once the Q is gone) win
  exactly the same tricks, so the robot plays the **cheaper** one.
- **Discard**: when it can neither follow suit nor usefully ruff, a robot
  throws the **lowest card outside trumps**, keeping its master cards if it
  can; between equally low cards, from its **longest** suit. It throws a
  trump only when it holds nothing else.

A robot always follows suit when it can: that is checked again by
`CardPlayService` like any card.

### Opening lead (a defender, first trick)

1. **Top of a sequence.** From a suit whose top two cards touch and the top
   one is a 10 or higher (A-K, K-Q, Q-J, J-10, 10-9), lead the top card. With
   several, the longest such suit (higher on a tie).
2. Otherwise lead from the **longest suit** (higher on a tie), but never
   trumps while there is another suit, and — **against a suit contract** — never a
   suit headed by the **ace**:
   - four or more cards: the **fourth highest** (fourth best);
   - three cards headed by the jack or higher: the **lowest**;
   - three small cards: the **highest** (top of nothing);
   - a doubleton: the **higher**; a singleton: it.
3. Against a suit contract with **only** ace-headed suits: lead the **ace**
   (never a low card under it). Against no trump, underleading an ace is
   allowed.

### A defender on lead later

Cash a **master card** outside trumps if it has one: the top card of the
first suit, in ♠ ♥ ♦ ♣ order, whose top card is a master. Otherwise lead as
for the opening lead.

### Declarer on lead (from its own hand or dummy's)

Tried in this order:

1. **Draw trumps.** In a suit contract, if the defenders may still hold
   trumps (13 less those played and those in declarer's and dummy's hands)
   and the hand on lead has one: lead the top trump if it is a master,
   otherwise the lowest trump.
2. **Cash a sure winner.** Lead a master card from the hand on lead (side
   suits first, then trumps).
3. **Lead towards a winner.** If the other hand holds the master card of a
   side suit and the hand on lead has a card in it, lead the lowest one.
4. **Ruff in the short hand.** In a suit contract, if the other hand has
   trumps and no cards in a side suit the hand on lead holds, lead the lowest
   card of that suit so the other hand ruffs.
5. **Set up the long suit.** Lead the lowest card of the side suit where
   declarer and dummy hold the most cards together (among suits the hand on
   lead holds).
6. Otherwise, the lowest card of the hand's longest suit.

### Following

**When it can follow suit:**

| Position | Play |
|---|---|
| second hand | **low** — the lowest card of the suit |
| third hand, partner's card is a master | **low** |
| third hand otherwise, and its highest card beats the card winning the trick | **high, cheaply**: the cheapest card equivalent to its highest one (holding K-Q-5 over a 3: the Q) |
| fourth hand, partner winning | **low** |
| fourth hand otherwise | the **cheapest card that wins** |
| any position, cannot beat the winning card (or the trick is already ruffed) | **low** |

**When it cannot follow suit:**

| Situation | Play |
|---|---|
| partner is winning the trick | **discard** |
| no trump contract, or no trumps left | **discard** |
| nobody has ruffed yet | **ruff** with the **lowest** trump |
| an opponent has ruffed | **over-ruff** with the lowest trump that beats it; if none does, **discard** |

Declarer's robot plays dummy's cards by the same rules, as dummy's seat.

## Claims

Robots **never claim** (nor withdraw). When a human claims, each robot that
must answer (the other non-dummy players) decides on its own:

1. A **concession** — a claim of 0 tricks — is always **accepted**.
2. Otherwise the robot works out its side's share of the remaining tricks if
   the claim stands (the claim itself if its partner claimed, the rest if an
   opponent did), and counts its side's **sure winners** from the hands it
   can see: its own, plus its partner's if that is face up (dummy, for a
   declarer robot; the claimer's hand, when its partner claimed).
3. It **accepts** if the share is at least the sure winners, and **rejects**
   otherwise. A rejection clears the claim and play goes on.

**Sure winners**, suit by suit: our cards from the top down, as long as no
card still out beats them (A-K-Q with the A, K and Q all ours count three),
but no more than the longer of our two hands holds in that suit. In a suit
contract, a side suit counts nothing if a face-up **opponent** (dummy, or the
claimer) has no cards in it and still holds a trump — it would be ruffed. A
side suit is otherwise counted as if nobody could ruff it.

Example: declarer claims all 3 remaining tricks in a spade contract; the
robot on defence holds the ♠A → one sure winner, a share of 0 → **rejects**.
A claim of 2 leaves it 1 → **accepts**.

## The next board

When a board is finished (played out, claimed or passed out), each robot asks
for the next board — the same as a human's `POST /tables/{table}/playing/next`
— one per event, straight away. The result stays on screen until the last
human at the table presses Next too, which deals the next board with the same
players in the same seats.

## What robots don't do

- No doubles or redoubles, no penalty doubles, no slams, no bidding above
  game, and no competing once the opponents have outbid them.
- No conventions: no Stayman, transfers, Blackwood, weak twos, preempts,
  takeout doubles, jump shifts, negative doubles or minor-suit raises.
- No signals or discarding methods, no finesses, no counting the
  opponents' cards, no inferences from the auction in the play.
- No claims.
- Points are HCP only; nothing for shape or trump support.
- A robot's "sure winners" for a claim ignore ruffs by a hand it can't see.

## Code map

| What | Where | Tests |
|---|---|---|
| hand evaluation | `App\Robots\RobotHand` | `tests/Unit/Robots/RobotHandTest` |
| bidding | `App\Robots\RobotBidder` (`choose()`, `shown()`) | `tests/Unit/Robots/RobotBidderTest` (every rule above, and 300 random deals bid by four robots, each call checked by `AuctionService`) |
| card play | `App\Robots\RobotCardPlayer`, `App\Robots\PlayView` | `tests/Unit/Robots/RobotCardPlayerTest` (every rule above, and 200 random deals played out, each card checked by `CardPlayService`) |
| claims | `App\Robots\RobotClaims` | `tests/Unit/Robots/RobotClaimsTest` |
| the pool and each move | `App\Services\RobotService` (`seatRobot()`, `act()`) | `tests/Feature/Game/RobotPlayTest`, `tests/Feature/Table/RobotSeatingTest` |
| the trigger | `App\Listeners\DriveRobots` (queued, after `PlayingUpdated`) | `tests/Feature/Game/RobotPlayTest` |
| settings | `config/bridge.php`: `robot_delay_seconds`, `unattended_table_minutes` | |

The decision classes are pure (no database) and read only the robot's view
of the state, so they can be unit-tested on hands written out by hand. How
the pieces fit together is in [`ARCHITECTURE.md`](ARCHITECTURE.md#robots);
running the queue worker the robots need is in
[`RUNNING.md`](RUNNING.md#robots).

# Robots

A **robot** is a computer player that takes a seat nobody else is in, so one
person can play (or test) the game alone. This page is the complete list of
what a robot does and how it decides: when it acts, what it can see, every
call it can make, how it leads and follows, and when it accepts a claim.

It describes the code as it is: `app/Robots/` and
`App\Services\RobotService`. The bidding is a SAYC-style system; the card
play is still rules of thumb ("v1"), and better card play is a separate
follow-up issue. For the endpoints see [`API.md`](API.md#tables);
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

A robot bids a **SAYC**-style system (Standard American Yellow Card):
five-card majors, a 15–17 1NT, a strong 2♣, weak twos, Stayman and Jacoby
transfers, takeout and negative doubles, Blackwood and Gerber. This section
is the whole system; a hand that fits none of it passes. Robots never
redouble.

### How a robot picks a call

The system is one ordered list of rules for each position in the auction
(`App\Robots\BiddingSystem`): a call, what it shows, and the hands that make
it. A robot makes the **first rule its hand fits whose call is legal now**,
and passes when there is none — so a bid an opponent's call has made
illegal is skipped and the next rule is tried.

The same list reads every call back, the robot's own and everyone else's
(a human's too): a call means what the rules that make it in that position
show. When several rules make it, it shows the widest of their ranges and
the shortest of their lengths. A call no rule makes (a human's convention,
say) is read as "Natural": it shows its suit and nothing else.

A robot adds up what its partner has shown **through the whole auction**:
each call's HCP range narrows the picture (a call that contradicts it
replaces it), the longest length shown in each suit is kept, and so is
whether the last call invites game. That is what "partner's minimum" and
"partner's maximum" mean below.

Each call's meaning has a short **explanation**, ready for bid alerts:
"Stayman: 8–17 HCP, asks for a four-card major", "Weak two: 5–11 HCP,
6+ ♥", "Takeout double: 12+ HCP, short in ♦, asks partner to pick a suit".
`RobotBidder::bid()` returns it with the robot's call, and
`RobotBidder::read()` explains every call of an auction. The API doesn't
send explanations yet.

### Hand evaluation

- **HCP** (high-card points): ace 4, king 3, queen 2, jack 1. Nothing is
  added for length or shortness.
- **Balanced**: no void, no singleton, at most one doubleton — 4-3-3-3,
  4-4-3-2 or 5-3-3-2.
- **Stopper** in a suit (for no trump): A, K-x, Q-x-x or J-x-x-x.
- **Longest suit** among some suits: the one with the most cards; on a tie,
  the higher-ranking (♠ > ♥ > ♦ > ♣).
- **Good suit** (to preempt in): two of the top three honours (A, K, Q) or
  three of the top five (A, K, Q, J, 10).
- **Points for a contract**: game needs 25 HCP between the partners, a small
  slam 33, a grand slam 37.

### 1. Opening (nobody has bid yet)

Checked in this order:

| Hand | Call |
|---|---|
| 22+ HCP | **2♣**: strong, artificial, forcing |
| 20–21 HCP, balanced | **2NT** |
| 15–17 HCP, balanced | **1NT** |
| 12+ HCP with a five-card major | **1♠** or **1♥**, the longer; **1♠** with 5-5 |
| 12+ HCP, no five-card major | **1♦** with more diamonds than clubs, or four of each; else **1♣** |
| 5–11 HCP, exactly six cards in ♠, ♥ or ♦, a good suit, no four-card side major | a **weak two** (2♠/2♥/2♦) |
| 5–10 HCP, eight or more cards in a major, a good suit | **4♥** / **4♠** |
| 5–10 HCP, seven or more cards in a suit, a good suit | **three** of it (a preempt) |
| anything else | Pass (shows 0–11) |

Nobody preempts or opens a weak two in fourth seat.

### 2. Partner opened no trump

This covers partner's 1NT (15–17), 2NT (20–21) and 2NT rebid after 2♣–2♦
(22–24), while the opponent passed or doubled (a double changes nothing).
With partner's range *lo–hi*, the robot's HCP decide:

| Zone | Over 1NT | Over 2NT (20–21) | Over 2♣…2NT (22–24) |
|---|---|---|---|
| invite: 25 − *hi* | 8–9 | — | — |
| game: 25 − *lo* over 1NT, 25 − *hi* higher | 10–15 | 4–11 | 1–8 |
| quantitative: 33 − *hi* | 16–17 | 12 | 9–10 |
| slam: 33 − *lo* | 18+ | 13+ | 11+ |

Checked in this order (the conventions are one level higher over 2NT):

| Hand | Call |
|---|---|
| slam zone | **4♣ Gerber** (asks for aces, see [slams](#11-slams)) |
| invite or game zone (game over 2NT), below slam, a four-card major — or five of one and four of the other | **Stayman**: 2♣ (3♣) |
| below slam, a five-card major (♠ with 5-5) — at any strength | a **Jacoby transfer**: 2♦ for ♥, 2♥ for ♠ (3♦ / 3♥) |
| quantitative zone | **4NT**: invites 6NT |
| game zone | **3NT**, to play |
| invite zone (over 1NT only) | **2NT**: invites 3NT |
| anything else | Pass |

**Opener answers.** Stayman: **2♥** with four hearts (even with four spades
too), **2♠** with four spades, **2♦** with neither (a level higher over
2NT). A transfer: bid the major; over 1NT, with 17 and four of it, jump to
**3** of it (a *super-accept*). A quantitative 4NT: **6NT** with the upper
half of the range (16–17 over 1NT), else pass. 1NT–2NT: 3NT with 16–17.

**Responder after Stayman:**

| Hand | Call |
|---|---|
| four of the major opener showed, game zone | **4** of it |
| … invite zone (1NT only) | **3** of it |
| after 2♦ (3♦), a five-card major, game zone | **3** of it, forcing to game: opener bids 4 of it with three, else 3NT |
| quantitative zone | **4NT** |
| game zone | **3NT** — after 2♥ it shows four spades, and opener with four spades bids **4♠** |
| invite zone (1NT only) | **2NT** |

**Responder after the transfer is completed:**

| Hand | Call |
|---|---|
| six or more of the major, game zone | **4** of it |
| … invite zone (1NT only) | **3** of it |
| five of the major, quantitative zone | **4NT** |
| … game zone | **3NT**: opener bids **4** of the major with three of it, else passes |
| … invite zone (1NT only) | **2NT** |
| weaker | Pass: the transfer is the contract |

**An opponent bid over partner's no trump** (the conventions are off):

| Hand | Call |
|---|---|
| 8+ HCP and four of their suit (their bid at the 3 level or lower) | **Double**, for penalties |
| 10+ HCP and their suit stopped | **3NT** |
| 5+ HCP and a five-card suit, at the 3 level or lower | that suit, to play |
| anything else | Pass |

### 3. Partner opened a strong 2♣

Responder bids **2♦** (waiting: artificial, any hand); over an overcall it
passes. Opener then rebids **2NT** with 22–24 balanced (and responder goes
on as over 2NT, [above](#2-partner-opened-no-trump)), **3NT** with 25–27
balanced, or else its longest suit at the cheapest level, which forces to
game. Over that suit responder bids, in order:

| Hand | Call |
|---|---|
| support (three of a major, four of a minor) and 8+ HCP | the cheapest raise |
| three of the major and less | **game** in it |
| 8+ HCP and a five-card suit of its own | that suit |
| 0–7 HCP, if 2NT is still legal | **2NT** |
| anything else | **3NT** |

After that the auction is forcing to game ([later bids](#10-later-bids)).

### 4. Partner preempted

**A weak two** (also partner's weak jump overcall), in order: **4♥/4♠**
with 16+ HCP and two cards of partner's major; **3NT** with 16+ HCP,
balanced, and the other three suits stopped; **three** of partner's suit
with three cards and 6–15 HCP (a preemptive raise); otherwise pass.
Opener passes whatever responder bids.

**A three-level preempt, or four of a major**: game in partner's major with
16+ HCP and two cards of it; over a minor, **3NT** with 16+ HCP and the
other three suits stopped; otherwise pass.

### 5. Partner opened one of a suit

Our first call; the opponent may have passed, doubled or overcalled (a
takeout double changes nothing). Checked in this order:

| Hand | Call |
|---|---|
| 19+ HCP and a five-card suit | a **jump shift** in it (1♣–2♠): forcing to game |
| partner opened a **major**, three of it, 6–10 HCP | **2** of the major |
| … 11–12 HCP | **3** of the major (a limit raise: invites game) |
| … 13+ HCP | **4** of the major |
| under 6 HCP | Pass |
| 19+ HCP and a four-card suit | a jump shift in it |
| the opponent overcalled | a **negative double**, or a penalty double of their 1NT (below) |
| a four-card or longer **major** biddable at the 1 level, 6–18 HCP | **1♥** with four of each major, else the longer (**1♠** with 5-5) |
| partner opened 1♣, four diamonds, 6–18 HCP | **1♦** |
| no overcall: balanced, 13–15 / 16–18 HCP | **2NT** / **3NT** |
| after an overcall, their suit stopped: 6–10 / 11–12 / 13+ HCP | **1NT** (if still legal) / **2NT** (invites) / **3NT** |
| 11–18 HCP and a four-card suit biddable at the 2 level | that suit (the longest; higher on a tie) |
| partner opened a **minor**: 6–10 HCP with five clubs / four diamonds | **2** of the minor |
| … 11–12 HCP with four of it | **3** of the minor (a limit raise) |
| no overcall, 6–10 HCP | **1NT** |

A new suit is forcing: opener must bid again.

**Negative double**: the opponent overcalled a suit at 2♠ or lower. The
double shows 6+ HCP (8+ over a two-level overcall) and four cards in every
major nobody has bid — both minors when both majors are gone — without a
five-card major that could be bid at the 1 level (that is bid instead).
Over a **1NT** overcall a double is for penalties: 10+ HCP.

### 6. Opener's rebid

We opened one of a suit and partner answered, with no bid by the opponents
since. A no trump or preempt opener goes straight to
[later bids](#10-later-bids), and so does opener after a raise or a 2NT/3NT
answer.

**Partner bid a new suit** (forcing, so this never passes):

| Hand | Call |
|---|---|
| partner's suit is a **major** and we hold four: 12–15 / 16–18 / 19+ HCP | the cheapest raise / a jump raise (invites) / game |
| a new four-card suit biddable at the **1 level**, 12–18 HCP | that suit (the longest; higher on a tie) — 1♣–1♦–**1♥** |
| balanced, 12–14 HCP | **1NT** (**2NT** over a two-level answer) |
| balanced, 18–19 HCP | **2NT** (**3NT** over a two-level answer) |
| a six-card suit: 12–15 / 16–18 / 19+ HCP in a major | our suit at the cheapest level / one higher (invites) / game (in a minor the jump is 16+) |
| 19+ HCP and a new four-card suit | a **jump shift** in it: forcing to game |
| a new four-card suit at the **2 level**, 12–18 HCP, ranking below our first — or above it (a **reverse**) with 17–18 | that suit — 1♦–1♥–**2♣**; a reverse (1♦–1♠–**2♥**) forces one more bid |
| partner's suit is a **minor** and we hold four: 12–18 / 19+ HCP | the cheapest raise / a jump raise, forcing to game |
| a five-card suit of our own | our suit at the cheapest level |
| 12–14 HCP, 1NT still legal | **1NT** |
| three cards in partner's suit | the cheapest raise |
| anything else | our suit at the cheapest level |

**Partner answered 1NT** (not forcing): a six-card suit as above, or a new
four-card suit at the 2 level (a reverse with 17–18); a balanced hand goes
on to [later bids](#10-later-bids) (pass, invite with 2NT, or 3NT).

**Partner made a negative double** (and the opponent passed):

| Hand | Call |
|---|---|
| four of the unbid major: 12–15 / 16–18 / 19+ HCP | it at the cheapest level / a jump (invites) / game |
| four of their suit with two of its top three honours | Pass, turning the double into a penalty double |
| balanced with their suit stopped: 12–14 / 18–19 HCP | the cheapest no trump / a jump in no trump |
| six of our suit and 16+ HCP | a jump in our suit (invites) |
| five of our suit, 12–15 HCP | our suit at the cheapest level |
| a new four-card suit up to the 2 level, below ours, 12–18 HCP | that suit |
| anything else | our suit at the cheapest level |

### 7. Overcalls and balancing

The opponents opened and our side has only passed. **Balancing** is the
pass-out seat — our pass would end the auction — where some calls need
less. Checked in this order:

| Hand | Call |
|---|---|
| they opened 1NT or 2NT, 15+ HCP | **Double**, for penalties |
| not balancing: 15–18 HCP, balanced, their suits stopped, and no trump biddable at the 1 or 2 level | **1NT** / **2NT** |
| balancing: 11–14 HCP, balanced, their suits stopped, 1NT still legal | **1NT** |
| a five-card suit they haven't bid (the longest), biddable at the **1 level**, 8–17 HCP (balancing 6–17) | that suit |
| … at the **2 level**, 11–17 HCP (balancing 9–17) | that suit |
| a six-card suit at the **3 level**, 13–17 HCP | that suit |
| their last bid is one or two suits at the 3 level or lower (or a 1NT answer): 12+ HCP (balancing 9+), two or fewer cards in each of their suits, three or more in each other suit (four with only two left) | a **takeout double** |
| 18+ HCP and their last bid is such a suit | a takeout double anyway (too strong to overcall) |
| not balancing: 5–10 HCP, a good six-card suit, our longest they haven't bid, a jump to the 3 level at most | a **weak jump overcall** (1♦–**2♠**) |
| anything else | Pass |

### 8. Advancing: partner overcalled or doubled

We haven't called anything but pass; the opponents opened.

**Partner made a takeout double and the opponent passed**, so we must bid:

| Hand | Call |
|---|---|
| their bid is at the 1 level; five of it with two of its top three honours, 8+ HCP | Pass: a penalty pass |
| a four-card major they haven't bid (the longest; ♠ on a tie): 0–8 / 9–11 / 12+ HCP | it at the cheapest level / a jump (invites) / **4** of it |
| their suits stopped: 6–10 HCP (1NT still legal) / 11–12 / 13+ | **1NT** / **2NT** (invites) / **3NT** |
| otherwise our longest suit they haven't bid: 0–8 / 9+ HCP | it at the cheapest level / a jump (invites) |

When the opponent **bid over the double** — or the double was a round ago —
we bid only with something to say: **4** of a four-card unbid major with
12+ HCP, or 6–11 HCP and a suit they haven't bid (four cards of a major,
five of a minor) at the 3 level or lower; otherwise pass.

**Partner overcalled in a suit:**

| Hand | Call |
|---|---|
| three of partner's suit, 6–10 HCP | the cheapest raise |
| … a major, 11–13 HCP | a jump raise (invites) |
| … a major, 14+ HCP | **4** of it |
| … a minor, 14+ HCP and their suits stopped | **3NT** |
| … a minor, 11+ HCP | a jump raise |
| a five-card suit of our own, 8–15 HCP, at the 2 level at most | that suit |
| their suits stopped: 8–11 HCP (1NT still legal) / 12–14 / 15+ | **1NT** / **2NT** (invites) / **3NT** |
| anything else | Pass |

**Partner's weak jump overcall**: as over a weak two
([§4](#4-partner-preempted)). **Partner's no trump overcall** (15–18, or
11–14 balancing): **3NT** with 25 − partner's minimum, **2NT** (invites)
between that and 25 − partner's maximum, else pass. **Partner's penalty
double** of their no trump: pass.

### 9. Doubles, all together

| Double | When | Shows |
|---|---|---|
| **takeout** | an overcall ([§7](#7-overcalls-and-balancing)) | 12+ HCP (9+ balancing), short in their suits, the other suits; asks partner to bid |
| **negative** | responder, over an overcall up to 2♠ ([§5](#5-partner-opened-one-of-a-suit)) | 6+ HCP, the unbid major(s) |
| **penalty**, of no trump | their 1NT/2NT opening (15+); their 1NT overcall of partner's suit (10+); our later bids (below) | points |
| **penalty**, of a suit | over partner's no trump ([§2](#2-partner-opened-no-trump)); later bids over a low contract ([§10](#10-later-bids)) | points and four of their suit |
| **penalty pass** | partner's takeout double, or partner's negative double | length and honours in their suit |

### 10. Later bids

Every call not covered above: the robot adds its HCP to the range its
partner has shown and places the contract. It **passes** when partner's
calls showed nothing the system understands, when the last bid is its own,
or when partner's last bid was a sign-off (a bid "to play").

**Where to play.** Game is **4** of a major with eight or more cards
between us (counting partner's shown length; ♠ on a tie) or with a
seven-card major of our own; otherwise **3NT** — or **5** of a minor with
an eight-card fit once 3NT is no longer legal. A slam goes in that major,
else a minor fit, else a six-card suit of our own, else no trump.

**Our side made the last bid**, checked in this order:

1. **Fourth suit forcing.** Responder's second bid, after opener bid two
   suits and responder one (and no opponent bid): with game values (our
   HCP + opener's minimum ≥ 25), no major fit, and no stopper in the
   fourth suit, bid the fourth suit at the cheapest level (3 at most):
   artificial, forcing to game. Opener answers with a raise of responder's
   major with three, else the cheapest no trump with a stopper in the
   fourth suit, else a six-card first suit, a five-card second suit, or the
   first suit again.
2. **Slam**, once per auction: when our HCP + partner's minimum reach 33,
   ask for aces — **4♣ Gerber** if partner's last bid was a natural no
   trump (1NT to 3NT), else **4NT Blackwood** — or, if that is no longer
   legal, bid six in the slam strain. With no fit, opposite partner's
   natural no trump with a narrow range (at most 4 points wide: 12–14,
   15–17 …) and our HCP + partner's maximum reaching 33, bid a
   **quantitative 4NT** instead (partner bids 6NT with the upper half).
3. The contract is already **game** or higher: pass.
4. The auction is **forcing to game** (2♣ and a suit rebid, a jump shift,
   opener's jump raise of a minor, fourth suit forcing, the five-card
   major after Stayman): bid game.
5. **Game is sure** (our HCP + partner's minimum ≥ 25): bid game.
6. **Partner invited**: accept (bid game) with at least the middle of the
   range **we** have shown, else pass.
7. **Nobody has invited** and game is possible (our HCP + partner's
   maximum ≥ 25): invite — **3** of the fit major, else **2NT**.
8. **Partner's last bid forces one more** (a reverse): the cheapest no
   trump up to 3NT, else the cheapest bid in partner's suit.
9. Otherwise pass.

**Answering for aces.** Blackwood: **5♣** none or four, **5♦** one, **5♥**
two, **5♠** three. Gerber: **4♦**, **4♥**, **4♠**, **4NT** the same. The
asker counts the aces ("none or four" is four when it holds none), then:
all four and 37+ HCP between us → a **grand slam**; one missing at most →
a **small slam**; otherwise **sign off** — pass if partner's answer is our
strain, else our strain at the cheapest level up to 5, else the cheapest no
trump. Partner passes the sign-off.

**The opponents made the last bid** (competing), checked in this order:

1. **Penalty double** of a low contract (the 2 level at most, not doubled
   yet): of a suit with 10+ HCP, four of it with two of its top five
   honours, and our HCP + partner's minimum ≥ 20; of no trump with 8+ HCP
   and our HCP + partner's minimum ≥ 23.
2. **Game** when it is sure, or when the auction is forcing to game.
3. **Compete** in a suit partner has shown, holding eight or more cards
   between us, as high as the *law of total tricks* allows — as many tricks
   as trumps between us (the level + 6), and never above the 3 level.
4. Otherwise pass.

Examples: 1♥–2♥: opener passes with 12–14, invites 3♥ with 15–18, bids 4♥
with 19+. 1♥–2♥–3♥: responder accepts 4♥ with 8–10, passes with 6–7.
1♥–2♥–(2♠): opener competes to 3♥ with six hearts (nine between us),
passes with five.

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

- No redoubles, and no running from a penalty double.
- No conventions beyond the ones above: no cue bids, Jacoby 2NT,
  splinters, new minor forcing, Michaels or the unusual 2NT, Lebensohl,
  Roman Key Card Blackwood or 5NT asking for kings, no 2NT asking a weak
  two for a feature, no negative doubles above 2♠, no lead-directing
  doubles.
- No competing above the 3 level except to bid a game that is sure.
- A call's meaning is read from its rules alone: when several rules make
  the same call, partner sees the widest of their ranges, not the hands the
  earlier rules have already taken.
- Bid explanations are worked out but not sent to clients (no alerts yet).
- No signals or discarding methods, no finesses, no counting the
  opponents' cards, no inferences from the auction in the play.
- No claims.
- Points are HCP only; nothing for shape or trump support.
- A robot's "sure winners" for a claim ignore ruffs by a hand it can't see.

## Code map

| What | Where | Tests |
|---|---|---|
| hand evaluation | `App\Robots\RobotHand` | `tests/Unit/Robots/RobotHandTest` |
| bidding: the system | `App\Robots\BiddingSystem` (the rules for each position), `App\Robots\BidRule`, `App\Robots\BidMeaning` (a call's meaning and explanation), `App\Robots\AuctionView` (the auction as one seat sees it, legal calls, `shown()`) | `tests/Unit/Robots/RobotBidderTest` |
| bidding: the robot | `App\Robots\RobotBidder` (`choose()`, `bid()` with the explanation, `read()`, `shown()`) | `tests/Unit/Robots/RobotBidderTest` (a case for each convention above, and 500 random deals bid by four robots: each call checked by `AuctionService`, and each robot's HCP inside the range its own call shows) |
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

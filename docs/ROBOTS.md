# Robots

A **robot** is a computer player that takes a seat nobody else is in, so one
person can play (or test) the game alone. This page is the complete list of
what a robot does and how it decides: when it acts, what it can see, every
call it can make, how it plans, leads, follows and signals, and when it
claims or accepts a claim.

It describes the code as it is: `app/Robots/` and
`App\Services\RobotService`. The bidding is a SAYC-style system; the card
play is declarer planning and technique, defence with standard signals, and
a search of the last few tricks. For the endpoints see [`API.md`](API.md#tables);
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
each of the three seats the creator didn't take. A table manager can also
fill any free seat: `POST /tables/{table}/seats/robots {"seat": "E"}`.

**Ready to start.** A robot is ready from the moment it sits down: its
seat's `ready_at` is set then (`TableSeatService::seat()`), so it shows as
`ready` and never has to press Start, and dealing doesn't clear it. A board
is dealt once the table is full and every **human** there has pressed Start
(`POST /tables/{table}/start`), so the creator of a robot table deals with
their one Start, and a robot taking the fourth seat after every human has
pressed it deals at once. A table only robots are keeping is never dealt to.
Filling the table deals nothing by itself, so the robots don't call before
the human has reached the table. Robots are `users` rows
with `is_robot: true`, named `Robot <n>` / `robot-<n>`, from a pool that
reuses idle robots and makes a new one when all are busy. They can't log in.

**When they act.** Only while **at least one human** sits at the table.
After every change to the game (every `PlayingUpdated`), and
`BRIDGE_ROBOT_DELAY_SECONDS` (1 s) later so a human can follow, **one**
robot makes **one** move if it is a robot's turn:

| Phase | The robot that moves | Its move |
|---|---|---|
| auction | the robot whose turn it is | one call ([Bidding](#bidding)) |
| play, no claim pending | the robot acting for `turn` — declarer's robot also plays dummy's cards | one card ([Card play](#card-play)), or a claim of the rest ([Claims](#claims)) |
| play, claim pending | the first robot (N, E, S, W order) that still has to answer | accept or reject ([Claims](#claims)) |
| finished, mid-set | the first robot not yet ready for the next board | ready ([The next board](#the-next-board)); nothing after a set's last board |

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

A robot picks its card by rules — declarer's, a defender's, following,
discarding — and in the **last four tricks** checks it with a search of
every way the unseen cards can lie ([The ending](#the-ending)). It always
follows suit when it can, which `CardPlayService` checks again like any
card.

### Words used here

- **Master card**: no card that could still be out beats it — every higher
  card of its suit has been played or is in a hand the robot's side can see.
  Declarer and dummy see both their hands; a defender sees only its own
  (dummy's cards are the opponents').
- **Equivalent cards**: two of our cards with nothing between them but
  cards played or held by our side (K-Q, or K-J once the Q is gone) win
  exactly the same tricks, so the robot plays the **cheaper** one.
- **Out** (or unseen): cards the robot can't see — not played, not in its
  own hand, not in dummy or (for declarer) the other hand.
- **Shown out**: a player who didn't follow suit has no more of that suit.
  Robots remember it for every seat.
- **Spot card**: a 10 or lower.

### Declarer's plan

Before each card declarer plays (the first time before dummy's first card)
the robot counts, from declarer's and dummy's hands:

- **Sure winners**, suit by suit: our cards from the top down while no card
  out beats them, no more than the longer hand holds — or every card of the
  longer hand when those top cards draw all the cards out (A-K-Q-J opposite
  x-x with four out: four).
- **Losers** (suit contracts), in the hand with more trumps (declarer's on a
  tie): of the first three rounds of each suit, those the opponents win, our
  best cards matched against theirs (K-Q-J-10-x of trumps: one; A-x-x
  opposite K: one; K-x-x opposite x-x: two); in a side suit, every card after
  the third too, unless the suit surely runs. A void loses nothing.
- **Ruffs**: losers of the long trump hand the other hand can ruff — in each
  side suit, the cards after the short hand runs out — no more than the short
  hand's trumps.
- **Needed**: the tricks still to win for the contract (level + 6 less those
  won), and **spare**: the tricks left less those needed.

The **line** follows:

| Contract | Situation | Line |
|---|---|---|
| no trump | sure winners ≥ needed | **cash** |
| no trump | otherwise | **develop** a suit |
| trumps | sure winners ≥ needed, or losers ≤ spare | **draw** trumps, then take the tricks |
| trumps | both hands have a void in a different side suit, the other hand holding cards there, and two or more trumps each | **cross-ruff** |
| trumps | ruffs to take | **ruff** losers in the short trump hand before drawing trumps |
| trumps | none of these | **draw** trumps, then develop |

The suit to **develop** is the side suit with the most tricks to gain: its
long cards once the cards out split evenly, plus our aces, kings and
queens in it, less its sure winners (longer on a tie, then higher).

### Declarer on lead (from its own hand or dummy's)

Tried in this order, the first that gives a card:

1. **Ruffing** (ruff and cross-ruff lines). On a cross-ruff, first cash a
   side-suit master that the other hand can follow to (so the defenders
   can't throw those suits away and ruff them later). Then, from the long
   trump hand (or either hand on a cross-ruff), lead the lowest card of a
   side suit the other hand is **void** in and has trumps for — when that
   card isn't a master. Else, from the long trump hand, **shorten** the
   ruffing hand: in a side suit where it has one or two cards and there are
   losers, cash our master there, or lead low to give the round up. On lead
   in the ruffing hand, cross to the other hand: low to its side-suit
   master.
2. **Draw trumps**, while the defenders may hold any and the hand on lead
   has one: the top trump if it is a master, else the lowest. On the ruff
   line only while the short trump hand keeps a trump for each ruff; never
   on a cross-ruff.
3. **Cash**, when the sure winners are enough, the **short hand's winners
   first** (so the suit isn't blocked): low to the other hand's master when
   that hand is shorter in the suit (A-K-4-3-2 opposite Q-5: the 2 to the
   queen); else the master of the suit the hand on lead is shortest in
   (relative to the other hand). Trumps last.
4. **Develop** the chosen suit, from the hand on lead:
   - **low towards an honour** in the other hand that a card out still
     beats: a **finesse** (x-x-x towards A-Q) or leading towards a king or
     queen (x-x towards K-x);
   - the **top of a sequence** (Q-J-10) towards the other hand's master, to
     run it as a finesse;
   - a **master**, the short hand's first (or low to the other hand's
     master when it is the shorter);
   - but when the honours to lead towards are in **this** hand (A-Q-x, or
     K-x-x, opposite small cards), **cross to the other hand** first: a low
     card to its sure winner in another suit;
   - the **top of a sequence** to knock out a higher card (K-Q-J: the K);
   - **low**, giving up a round to set up length.
5. Otherwise: a master; low towards the other hand's master; the lowest
   card of the side suit we hold most of together.

### Declarer following

**Second hand** (a defender led): low while the fourth hand (ours) can beat
the card led; otherwise win an honour lead (10 or higher) cheaply, unless
[holding up](#holding-up-in-no-trump); else low.

**Third hand** (the other hand led):

- low when our card is winning and can't lose: a master, or an honour from
  a **sequence** the other hand still holds the next card of (Q from
  Q-J-10) that second hand didn't cover — it is **left to run**;
- low when **ducking keeps an entry**: in no trump, this hand is long in the
  suit (three or more cards longer than the other hand), holds masters but
  too few to draw the cards out, has no sure winner in another suit to get
  back to it by, it is the suit's first round, and the other hand keeps a
  card to lead it again (A-K-x-x-x-x opposite x-x: duck, then the A-K draw
  the rest and the suit runs);
- otherwise **finesse or drop**: with a master, and a card out between it
  and our cheapest winning card, play the **finesse** — our best card below
  the highest card out (A-Q over a low card: the Q; A-K-J-9 missing the Q:
  the J) — unless the **drop** is the better chance: the opponents held no
  more than twice as many cards of the suit this round as we hold above the
  missing card ("eight ever, nine never": nine cards missing the queen, play
  the ace-king; eight, finesse). If second hand has **shown out**, the
  finesse can't work: play the master. If fourth hand has shown out, the
  cheapest winning card is enough. Without a master (K-x): our best card.

**Fourth hand**: low when our card is winning; otherwise the cheapest card
that wins, unless holding up.

**Void in the suit led**: throw a card ([Discards](#discards)) when our
other hand's card wins for sure (a master; or, second hand, a master still
to come from the fourth hand; or ours is winning in fourth place) or when
there are no trumps; else ruff with the lowest trump, or over-ruff an
opponent's ruff with the lowest trump that beats it (throwing a card when
none does).

Declarer's robot plays dummy's cards by these rules too, as dummy's seat.

#### Holding up in no trump

When the defenders lead a suit in which our side has exactly **one sure
winner** (A-x-x), and the sure winners don't already make the contract,
declarer ducks by the **rule of 7**: 7 less our cards in the suit (both
hands, as dealt) is how many rounds to let go, so the defender with the
long suit is cut off once the stopper goes. It needs two or more cards in
the hand playing, to have one to duck with.

### A defender's opening lead

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

Tried in this order:

1. **Give partner a ruff**: in a suit contract, when partner has shown out
   of a side suit this hand holds, and may still have trumps (hasn't shown
   out of them, and some are out), lead that suit — with a
   [suit-preference](#signals) card.
2. **Return the suit asked for**: having just ruffed partner's lead, lead
   the side suit partner's card asked for (its master, else the lowest).
3. **Cash a master** outside trumps: the top card of the first suit, in
   ♠ ♥ ♦ ♣ order, whose top card is a master.
4. **Partner's suit**: back to the first side suit partner led — the higher
   of two cards left, else the lowest.
5. **Continue or switch**: on with the suit this hand first led if partner
   **encouraged** it (the top card when it is a master or tops a sequence,
   else the lowest); otherwise a new suit, chosen as an opening lead would
   be but away from suits partner **discouraged** (on our lead, or with a
   low discard) — and to a suit partner asked for with a high discard first.

### A defender following

**Second hand**: low, except:

- to take the trick that **beats the contract** with a master (the
  defenders need one more);
- to **cover an honour** (10 or higher) led from dummy with the cheapest
  card that beats it — unless dummy still holds the next card below it
  (a sequence: covering gains nothing);
- the ace [held up against dummy's long suit](#holding-up-an-ace-against-dummy),
  taken on the right round.

**Third hand**:

- nothing to win when partner's card wins for sure: it is a master, or
  dummy plays last and has nothing to beat it;
- with **dummy playing last**, only as high as needed: the cheapest card
  that beats both the trick so far and dummy's best (K-J-x under dummy's
  10-x: the J); when nothing beats dummy's best, the cheapest winning card
  anyway unless partner is winning;
- otherwise **high**: the cheapest of equals of our best card.

**Fourth hand**: low when partner is winning; otherwise the cheapest card
that wins.

When not playing to win, a defender **signals**: attitude on partner's
lead, count on declarer's ([Signals](#signals)).

**Void in the suit led**: throw a card when partner is winning (unless
dummy, still to play, can beat partner's card and we can ruff it) or with
no trumps; else ruff low, or over-ruff with the lowest trump that beats an
opponent's ruff (never over partner's), else throw a card.

#### Holding up an ace against dummy

In no trump, when declarer leads a suit from hand towards dummy's **long
suit** (three or more cards) and dummy has **no sure winner in another
suit** to get back by, a defender who can win with a master **ducks** until
the round on which declarer plays its **last** card of the suit, then takes
it — dummy's long cards are cut off. Declarer's length comes from
**partner's count signal**: the cards neither this hand nor dummy holds are
partner's and declarer's; partner's first card in the suit says whether
partner has an even (high) or odd (low) number, and the most even split
that fits is taken (five cards between them, partner low: partner three,
declarer two — take the second round). With no signal yet, declarer is
given the bigger half.

### Signals

Defenders give and read standard signals:

| Signal | When | Given |
|---|---|---|
| **attitude** | following partner's lead, not winning | the **highest spot card** (not a master) to say *go on* — holding the A, K or Q of the suit, or the J when partner led an honour; the **lowest** card to say *switch* |
| **count** | following declarer's lead the first time the suit is played, not winning | high (the highest spot card) with an **even** number of cards, the **lowest** with an odd number |
| **suit preference** | leading a card for partner to ruff | the **highest spot card** asks for the higher-ranking of the other two side suits back, the **lowest** for the lower one — asking for the suit where this hand holds an ace or a master (neither or both: the lowest) |
| **discards** | throwing a card | the robot throws the lowest card of a suit it can spare, which reads as *don't lead this* |

**Reading partner's card.** A spot card is **high** when more of the spot
cards of its suit the robot couldn't see *when it was played* are lower
than it than are higher (those still unseen, and those played afterwards
from a hand it doesn't see). An honour, or a trick partner won, counts as
encouraging. After ruffing partner's lead, a high lead asks for the
higher-ranking of the two other side suits, a low one for the lower. A
**first discard** in a suit asks for it when high and not when low.

### Discards

When a robot can neither follow suit nor usefully ruff, it throws the
**lowest card** of the suit it can best spare, never a master while another
card will do, and a trump only when it has nothing else. The suit it keeps
first:

1. a suit with nothing but masters;
2. a suit where every card **guards an honour**: a J or higher that isn't a
   master, with no more cards than the cards above it still out plus one
   (K-x, Q-x-x, J-x-x-x);
3. for a defender, a suit **no longer than dummy's**, holding a card that
   beats dummy's lowest: throwing one would let dummy's last card win;
4. then the shorter suits.

So it throws from the longest suit it can spare.

### The ending

With **four tricks or fewer** left, the card the rules picked is checked:
every way the cards this robot can't see may be split between the two
hands it can't see is tried — as many cards as each still holds, none in a
suit a player has shown out of — and each **layout** is solved **double
dummy** (every hand known, everyone playing perfectly;
`App\Robots\DoubleDummy`). The card that takes the most tricks over all the
layouts is played: the rules' card if it is one of the best, else the
lowest of the best. There is no search when the cards can lie in more than
80 ways, when every card the robot may play is equivalent, or before dummy
is face up. The robot still sees only its own seat's cards: the hidden
hands are guessed, all of them.

### Robots against v1

The first robots ("v1") played by rules of thumb only: top of a sequence
or fourth best, second hand low, third hand high, declarer drawing trumps
and cashing winners, no plan, finesse, hold-up, signal or search. To
measure the change, four robots bid 1000 seeded random deals (972 reached
a contract) and each deal was played out four times, v1 and today's play
on each side:

| Declarer's side | Defenders | Contracts made | Declarer's tricks, average |
|---|---|---|---|
| v1 | v1 | 540 (55.6%) | 8.26 |
| today's | v1 | 686 (70.6%) | 8.85 |
| v1 | today's | 476 (49.0%) | 8.08 |
| today's | today's | 631 (64.9%) | 8.64 |

Today's declarer makes about 15 contracts in 100 more than v1 against the
same defence, and today's defence beats about 7 in 100 more of v1's
declarers. `tests/Unit/Robots/RobotSimulationTest` runs the same comparison
on 60 deals and requires both.

## Claims

**A robot claims** when it is on lead (for its own seat, or declarer's
robot for dummy) and the hand on lead holds nothing but **top winners**:
every card a master, and in a suit contract no more trumps out than the
hand's own (all masters, so leading them first draws the rest). It claims
**all** the tricks left. The two other non-dummy players answer as for any
claim — robots by the rules below, humans themselves. A robot claims only
once from a given point of the play (`RobotService` remembers it in the
cache for a day): if the claim is rejected, it plays on, and may claim
again after more cards.

**Answering a claim.** When someone claims, each robot that must answer
(the other non-dummy players) decides on its own:

1. A **concession** — a claim of 0 tricks — is always **accepted**.
2. Otherwise the robot works out its side's share of the remaining tricks if
   the claim stands (the claim itself if its partner claimed, the rest if an
   opponent did), and compares it with what its side would take:
   - with **six tricks or fewer** left, **double dummy**: while a claim is
     pending the robot sees three hands (its own, dummy's and the
     claimer's), so it knows the fourth too — the cards nobody has played
     and none of the three holds — and solves the ending exactly;
   - with more, its side's **sure winners** from the hands it can see.
3. It **accepts** if the share is at least that, and **rejects** otherwise.
   A rejection clears the claim and play goes on.

**Sure winners**, suit by suit: our cards from the top down, as long as no
card still out beats them (A-K-Q with the A, K and Q all ours count three),
but no more than the longer of our two hands holds in that suit. In a suit
contract, a side suit counts nothing if a face-up **opponent** (dummy, or the
claimer) has no cards in it and still holds a trump — it would be ruffed. A
side suit is otherwise counted as if nobody could ruff it.

Examples: declarer claims both of the last 2 tricks in no trump with the
♥A-Q, dummy on lead, and the robot on defence holds the ♥K behind the
A-Q. It sees no sure winner, but double dummy it takes one trick → a share
of 0 is too little → **rejects**. With the ♥K in front of the A-Q (the
finesse works) it **accepts**. With 8 tricks left, declarer claims them all
and the robot holds the ♠A: one sure winner → **rejects**.

## The next board

When a board is finished (played out, claimed or passed out), each robot asks
for the next board — the same as a human's `POST /tables/{table}/playing/next`
— one per event, straight away. The result stays on screen until the last
human at the table presses Next too, which deals the next board with the same
players in the same seats. If a human left and somebody else took the seat,
Next is refused (the robot's attempt is dropped and logged) and the humans'
Start deals the next board instead; the robots are ready already.

After the **last board of a set** there is no Next to ask for: the robots
don't ask (`ready` stays `[]`), and their Start (`table_seats.ready_at`,
set when they sat down) still stands, so the humans' Start opens the next
set — a human alone with three robots presses it once.

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
- No inferences from the auction in the play: nobody places an honour or
  a long suit from the bidding, and the ending search takes every layout
  as equally likely.
- Declarer's plan is remade before every card from the cards left, with no
  memory of what it meant to do: no squeezes, endplays, safety plays,
  combining chances (try one suit before taking a finesse in another) or
  guessing two-way finesses — except what the search of the last four
  tricks finds.
- Defenders read attitude only on their own first lead, count only to
  hold up an ace against dummy's long suit, and suit preference only after
  a ruff; no trump echo or Smith echo, no forcing defence, no uppercuts, no
  ducking other than against dummy's long suit.
- Robots claim only all the tricks left, from top winners in the hand on
  lead: no partial claims, no concessions, and they never withdraw a claim.
- Points are HCP only; nothing for shape or trump support.
- With more than six tricks left, a robot's "sure winners" for a claim
  ignore ruffs by a hand it can't see.

## Code map

| What | Where | Tests |
|---|---|---|
| hand evaluation | `App\Robots\RobotHand` | `tests/Unit/Robots/RobotHandTest` |
| bidding: the system | `App\Robots\BiddingSystem` (the rules for each position), `App\Robots\BidRule`, `App\Robots\BidMeaning` (a call's meaning and explanation), `App\Robots\AuctionView` (the auction as one seat sees it, legal calls, `shown()`) | `tests/Unit/Robots/RobotBidderTest` |
| bidding: the robot | `App\Robots\RobotBidder` (`choose()`, `bid()` with the explanation, `read()`, `shown()`) | `tests/Unit/Robots/RobotBidderTest` (a case for each convention above, and 500 random deals bid by four robots: each call checked by `AuctionService`, and each robot's HCP inside the range its own call shows) |
| card play | `App\Robots\RobotCardPlayer` (`choose()`), `App\Robots\PlayView` (what the seat knows: hands it sees, cards out, voids, tricks needed), `App\Robots\DeclarerPlan` (the count and the line), `App\Robots\DeclarerPlay`, `App\Robots\DefenderPlay`, `App\Robots\Signals`, `App\Robots\Discards`, `App\Robots\Endgame` (the last four tricks) | `tests/Unit/Robots/RobotCardPlayerTest` (a case for each technique above, and 80 random deals played out, each card checked by `CardPlayService`), `tests/Unit/Robots/DeclarerPlanTest` |
| double dummy | `App\Robots\DoubleDummy` (`tricks()`, `cardValues()`: an exhaustive search with alpha-beta, fine for endings of a few tricks) | `tests/Unit/Robots/DoubleDummyTest` (against a plain minimax on random endings) |
| claims | `App\Robots\RobotClaims` (`claim()`, `accepts()`, `doubleDummy()`, `sureWinners()`) | `tests/Unit/Robots/RobotClaimsTest`, `tests/Feature/Game/RobotPlayTest` |
| robots against v1 | `tests/Unit/Robots/Support/` (`RobotTable`: four robots bid and play a deal in memory; `V1CardPlayer`: the first robots' card play, kept as the baseline) | `tests/Unit/Robots/RobotSimulationTest` ([Robots against v1](#robots-against-v1)) |
| the pool and each move | `App\Services\RobotService` (`seatRobot()`, `act()`) | `tests/Feature/Game/RobotPlayTest`, `tests/Feature/Table/RobotSeatingTest` |
| the trigger | `App\Listeners\DriveRobots` (queued, after `PlayingUpdated`) | `tests/Feature/Game/RobotPlayTest` |
| settings | `config/bridge.php`: `robot_delay_seconds`, `unattended_table_minutes` | |

The decision classes are pure (no database) and read only the robot's view
of the state, so they can be unit-tested on hands written out by hand. How
the pieces fit together is in [`ARCHITECTURE.md`](ARCHITECTURE.md#robots);
running the queue worker the robots need is in
[`RUNNING.md`](RUNNING.md#robots).

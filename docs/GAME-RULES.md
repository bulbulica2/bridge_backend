# Contract Bridge — Domain Primer

_What the app is for, the rules it models, and how those rules map onto the
backend._

Sources: [Wikipedia — Contract bridge](https://en.wikipedia.org/wiki/Contract_bridge),
[Bicycle Cards — How to play Bridge](https://bicyclecards.com/how-to-play/bridge),
[European Bridge League — What is bridge](https://www.eurobridge.org/what-is-bridge/).
Scoring numbers follow the standard (WBF/ACBL) duplicate scoring table.

## What the app is

An online contract bridge platform: users register, sit at a **table** in one
of the four seats, and play a **board** (a pre-dealt hand) through its two
phases — the **auction** (bidding) and the **card play** (13 tricks). The
result is then recorded per board per table.

The data model is shaped for **duplicate bridge**: a `Board` is a stored deal
that several `Table`s can play (the `tables.board_id` FK; board selection
hands the same board to several tables as long as none of their players has
played it), and the `board_table` record
stores who sat where, the contract, tricks and score per board+table — which
is exactly what duplicate needs to compare results across tables.

Design intent (from the project owner):
- A table has users (`table_seats`) and a **current board** (`tables.board_id`).
- A board keeps a **history** of the tables that played it and when
  (`board_table`). A board can be played by many tables, but **the same table
  never plays the same board again**.
- Boards should rotate as much as possible so players don't recognise a deal
  if they meet it again (see the board-selection rule in §8).

---

## 1. Players, partnerships, seats

- 4 players in 2 fixed partnerships. Partners sit opposite each other.
- Seats are named by compass direction: **North–South** vs **East–West**.
- Play and bidding proceed **clockwise**: N → E → S → W → N.

**In code:** `Seats::SEATS = ['N','E','S','W']` (already in clockwise order).
`table_seats` maps a `user_id` to a `seat` at a `table_id`. The database
enforces one user per seat per table (`unique(table_id, seat)`) and one table
per user (`unique(user_id)`). Leaving deletes the row; a table exists only
while someone sits at it, so the last player out deletes the table.

## 2. Cards and the deal

- Standard 52-card deck, no jokers. Each player gets 13 cards.
- Suit rank (high → low): **Spades ♠, Hearts ♥, Diamonds ♦, Clubs ♣**.
  Suit rank matters for bidding only; in play no suit beats another except
  the trump suit.
- Card rank within a suit (high → low): A K Q J 10 9 8 7 6 5 4 3 2.
- In rubber/social bridge the dealer deals one card at a time clockwise, and
  the deal rotates clockwise each hand. In **duplicate**, the deal is fixed on
  a **board** so it can be replayed identically at other tables.

**In code:**
- `cards` holds the 52 cards once (seeded by `CardSeeder`). `rank` encoding:
  `2`–`10` = pip value, **`11` is skipped**, `12`=Jack, `13`=Queen,
  `14`=King, `15`=Ace. Higher `rank` = higher card, so comparisons work; just
  don't assume ranks are contiguous.
- A board's deal is the `board_card` pivot (`board_id`, `card_id`, `seat`):
  13 rows per seat. `BoardSeeder` shuffles the 52 ids and chunks them. The
  primary key `(board_id, card_id)` stops a card being dealt twice on one
  board (it doesn't check 13 cards per seat).

## 3. Boards: dealer and vulnerability

In duplicate, every board number fixes **who deals** and **who is
vulnerable** (vulnerability raises both bonuses and penalties). The pattern
repeats every 16 boards:

- **Dealer** = board number mod 4: 1→N, 2→E, 3→S, 0 (4)→W.
- **Vulnerability:**

| Board | Dealer | Vul | | Board | Dealer | Vul |
|---|---|---|---|---|---|---|
| 1 | N | None | | 9 | N | E-W |
| 2 | E | N-S | | 10 | E | Both |
| 3 | S | E-W | | 11 | S | None |
| 4 | W | Both | | 12 | W | N-S |
| 5 | N | N-S | | 13 | N | Both |
| 6 | E | E-W | | 14 | E | None |
| 7 | S | Both | | 15 | S | N-S |
| 8 | W | None | | 16 | W | E-W |

**In code:** `boards` stores `number` (unsigned int), `dealer` (enum
`Seats::SEATS`) and `vulnerable` (enum `Vulnerability::VULNERABILITY_SEATS`
= `'N-S'`, `'E-W'`, `'N-S E-W'` (both), `''` (none); also exposed as
`Vulnerability::NS/EW/BOTH/NONE`).
The cycle above is implemented by `Seats::dealerForBoard(int)` and
`Vulnerability::forBoard(int)` (covered by `tests/Unit/BoardCycleTest.php`).
`BoardFactory` gives boards sequential numbers and derives `dealer` and
`vulnerable` from them. The database doesn't check that the three columns
agree, so code that creates boards outside the factory must use the helpers.

## 4. The auction (bidding)

Starting with the dealer and going clockwise, each player makes one **call**:

- **Bid** = a level **1–7** plus a strain. The level is tricks *above six*
  ("book"): `1♠` promises 7 tricks, `7NT` all 13.
- **Strain order** (low → high): ♣ < ♦ < ♥ < ♠ < **NT** (no trump).
  Every bid must be higher than the last bid: a higher level, or the same
  level in a higher strain. So the 35 bids have a strict order
  `1♣ 1♦ 1♥ 1♠ 1NT 2♣ … 7NT`.
- **Pass** — always legal.
- **Double (X)** — legal only when the **last non-pass call** is a contract
  bid made by an **opponent**. Passes in between don't matter: after
  N `1♥`, E `P`, S `P`, West may still double 1♥. It is illegal on your
  partner's bid, and on a bid that is already doubled (then the last
  non-pass call is the `X`, not the bid).
- **Redouble (XX)** — legal only when the **last non-pass call** is an `X`
  made by an **opponent**, i.e. your side's bid has been doubled and nobody
  has bid since. Passes in between don't matter here either: N `1♥`,
  E `X`, S `P`, W `P`, N `XX` is legal (so is S redoubling straight away).
  It can't be redoubled twice.
- A new bid cancels any X/XX.
- **End of auction:** after a bid/X/XX is followed by **three consecutive
  passes**. If all four players pass at the start, the board is **passed
  out** (no play, scores 0).

**Final contract** = the last bid + whether it is doubled/redoubled.
**Declarer** = the player on the winning side who **first** named that
contract's strain. **Dummy** = declarer's partner.

**Alerts.** A partnership may play conventions: calls that mean something
other than what they say (Stayman's 2♣ asks for a major, a transfer's 2♦
shows hearts, a strong 2♣ says nothing about clubs). The opponents are
entitled to know what the calls mean, partner isn't — partner learning it
from anything but the calls themselves is *unauthorised information*. Online
bridge therefore uses **self-alerts**: the bidder marks their own call as
alerted and types its meaning, which goes to the two opponents only. An
opponent may also **ask** about any call of the other side, alerted or not,
and its bidder answers them the same way. Once the board is over nothing is
hidden: every alert is shown to all.

**Table talk.** For the same reason partners may not talk to each other
while a board is bid or played: anything partner says beyond the calls and
cards is unauthorised information. A player may talk to the **opponents**
— to ask what a call shows and hear the answer in the bidder's own words,
which partner must not read along — and the whole table may talk once the
board is over. After the board, everything said at it is open to all.

**In code:**
- `bids` is a static list of the 38 possible calls (`BidSeeder`): `P` Pass,
  `X` Double, `XX` Redouble (`special = true`), then `1C`, `1D`, `1H`, `1S`,
  `1NT`, `2C` … `7NT` (`special = false`).
- A contract bid carries its rank in two columns: `level` (1–7) and `strain`
  (`C`, `D`, `H`, `S`, `NT`). Both are null for the three special calls, which
  have no rank of their own. `Bid::isHigherThan()` compares
  `[level, strainRank]`, where `Suits::strainRank()` reads the
  ♣ < ♦ < ♥ < ♠ < NT order straight off the declaration order of
  `Suits::ALL_SUIT_NAMES`. Comparing a special call throws.
- The seeder does insert bids in rank order, so ids happen to ascend with
  rank — but **nothing enforces that**, so ordering by `id` is not safe and
  `Bid::rank()` never looks at it. `Bid::contracts()` and `Bid::specials()`
  scope the two groups.
- `auctions` holds one row per call: `board_table_id` (the playing — one
  board at one table), `user_id` (the caller), `bid_id`, `seat` (the caller's
  seat, enum `Seats::SEATS`). `BoardTable::auctions()` returns a playing's
  calls. Call order = row `id`. The first caller is `boards.dealer`.
- When the auction ends, its result is **saved** on the playing's
  `board_table` row: `contract_bid_id` (the final bid), `doubled` (0 none,
  1 X, 2 XX), `declarer_seat`, `declarer_id` and `auction_ended_at`. A passed
  out board has `auction_ended_at` set and `contract_bid_id` null. Dummy is
  `Seats::partner(declarer_seat)`.
- **Enforced** by `App\Services\AuctionService` behind
  `POST /tables/{table}/calls`. The rules are static functions over the list
  of calls so far (`PlayingStateService::calls()`, ordered by row id):
  `nextToCall()` (dealer first, then `Seats::next()` of the last caller; null
  once over), `illegalReason()` (bid must be `isHigherThan()` the last bid;
  X/XX checked against the last call that isn't a pass and whether its seat
  is on the caller's side, i.e. the caller or `Seats::partner()`), `isOver()`
  (four or more calls, the last three passes) and `result()` (last bid, X/XX
  after it, declarer). `Bid::isPass()/isDouble()/isRedouble()/isContract()`
  tell the calls apart by their `suit` code (`P`, `X`, `XX`).
- `AuctionService::call()` locks the `board_table` row for the whole call, so
  concurrent calls queue and each is checked against the ones before it. When
  the auction ends it saves the result as above; a passed out board also gets
  `score = 0` and `finished_at` (`BoardTable::finish()`). Every
  accepted call dispatches `PlayingUpdated`.
- **Alerts** (implemented): `POST /tables/{table}/calls` takes `alert` and
  an `explanation` (up to 200 characters; a non-empty one alerts the call),
  stored as `auctions.alerted`/`explanation`. `AuctionService::alertTo()`
  pushes it to the bidder's two opponents (`CallAlerted`, their own
  channels); `PlayingStateService::alerts()` shows each call's alert in a
  player's own state for their own and the opponents' calls, never
  partner's, and to everyone once the board is finished
  (`GET /playings/{playing}` too). `PlayingUpdated`, on the table channel,
  carries none. An opponent asks with
  `POST /tables/{table}/calls/{index}/question` (`AuctionService::ask()`:
  the other side's calls only, until the board is finished, one open
  question per call, stored as `auctions.question_seat`); the bidder gets
  `CallQuestioned` and answers with
  `PUT /tables/{table}/calls/{index}/explanation` (`explain()`, which also
  fixes an explanation or alerts late), and a robot answers at once (§9).
  Not enforced: nothing checks that an alert is made, or that it is true —
  a missing or wrong explanation is left to the players, as there is no
  director. The question and the answer are also written into the board's
  chat (below).
- **Table talk** (implemented): the board's chat, `board_messages`
  (`BoardChatService`, `GET`/`POST /tables/{table}/messages`). During the
  auction and the play a message may only go `to: opponents` — the sender
  and both opponents read it, never partner (`BoardMessage::visibleTo()`),
  and it is pushed only on those players' own channels
  (`BoardMessageSent`), never the table channel; `to: table` (all four) is
  a 409 until the board is `finished`. Once it is, every message of the
  board is visible to all four and in the review (`GET /playings/{playing}`).
  A message with `call_index` about the other side's call is a question; a
  robot bidder answers it at once (§9). Not enforced: what is said — a
  player could tell the opponents something meant for partner's ears, who
  reads it once the board is over; there is no director.
- `AuctionSeeder` bids through `AuctionService`, picking random calls among
  the legal ones, so seeded auctions are legal and their results saved the
  same way. `AuctionFactory` still makes a **random, non-legal** call: test
  filler only.

## 5. The play

- The player to **declarer's left** makes the **opening lead**. Then dummy
  lays all 13 cards face up, sorted by suit; **declarer plays both hands**.
- A **trick** = one card from each player, clockwise, starting with the
  leader.
- You **must follow suit** if you can. If you can't, you may play any card,
  including a trump.
- The trick is won by the highest **trump** in it, or, if there is no trump,
  the highest card **of the suit led**. In NT there is no trump suit.
- The winner of a trick leads to the next one. 13 tricks per board.

**In code:** `cardplays` holds one row per card played: `board_table_id`
(the playing), `user_id`, `card_id`, `seat` (enum `Seats::SEATS`, the hand the
card came from), `round` (trick number 1–13), `order` (1–4 position within
the trick). `user_id` is who played the card and `seat` is whose hand it came
from, so when declarer plays from dummy the row has declarer's `user_id` and
dummy's `seat`.
- **Opening lead:** `Seats::next(board_table.declarer_seat)`, the seat on
  declarer's left. It plays `round = 1`, `order = 1`.
- **Trick winner:** when the 4th card of a trick is played, the winning card
  gets `won_trick = true`. That row's `seat` leads the next trick, and
  declarer's tricks = winning rows whose `seat` is declarer's or dummy's.
- The DB rejects the same card twice in a playing
  (`unique(board_table_id, card_id)`) and two cards in one trick position
  (`unique(board_table_id, round, order)`). `BoardTable::cardPlays()` returns
  a playing's cards.
- `App\Services\CardPlayService` enforces all of this for
  `POST /tables/{table}/cards`. Like the auction, the rules are static
  functions over the list of plays (`nextToPlay`, `actingSeat`,
  `illegalReason`, `trickWinner`, `tricks`, `tricksWon`), unit-tested without
  a database in `tests/Unit/CardPlayServiceTest`:
  - **Turn:** declarer's left leads trick 1, then clockwise; each trick's
    winner leads the next. Each player plays their own hand, except dummy's,
    which declarer plays; dummy's own player is always refused — except
    with a robot declarer and a human dummy, where the human plays both
    hands and the robot declarer is refused instead (§9).
  - **Legal card:** in the hand being played (its `board_card` rows less what
    is already in `cardplays`), not already played (checked before the unique
    index), and of the suit led if that hand still holds it.
  - **Trick winner:** the highest trump if any was played, else the highest
    card of the suit led; NT (`contractBid.strain = 'NT'`) has no trump. Card
    `rank` is compared, never assumed contiguous (it skips 11). The winning
    row gets `won_trick = true` when the 4th card is played.
  - **End of play:** after the 13th trick the board is scored (§6):
    `tricks_won` (declarer's side), `score` and `finished_at` are written.
  - The `board_table` row is locked for the whole request, so simultaneous
    cards queue; each accepted card dispatches `PlayingUpdated`.
- **Dummy** is face up to all four players, and on the table channel, as
  `dummy_hand` once the opening lead is made; it is null before that.
- `CardplaySeeder` plays through `CardPlayService`, a random legal card at a
  time (declarer playing dummy's), so seeded tricks, winners and scores are
  real. `CardplayFactory` still makes a **random, non-legal** row: test
  filler only.

**Claims and concessions** (Laws 68–70, simplified for online play): a
player may stop the play by claiming some or all of the remaining tricks —
typically when the rest is obvious (declarer holds all the trumps, a defender
has only winners left). Claiming none of them is a **concession**. At a real
table the claimer faces their hand and states a line of play; the others
agree, or the director is called.

**In code:** `App\Services\ClaimService`, over `POST /tables/{table}/claim`
(`{tricks}`), `POST /tables/{table}/claim/response` (`{accept}`) and
`DELETE /tables/{table}/claim` (withdraw). The pending claim lives on
`board_table` (`claim_seat`, `claim_tricks`, `claim_accepted`,
`claim_expires_at`). The rules are
static and unit-tested without a database in `tests/Unit/ClaimServiceTest`
(`illegalPlayerReason`, `responders`, `remaining`, `tricksReason`,
`declarerTricks`):
- **Who:** only during the `play`, and any player **except dummy**, whose own
  player can neither claim nor answer — except a human dummy playing for a
  robot declarer (§9), who claims, answers and withdraws for declarer's
  seat, while that robot declarer does none of it.
- **How many:** 0 up to the tricks still to play — 13 less the complete
  tricks, so a trick in progress counts as remaining.
- **Face up:** while the claim is pending, the claimer's remaining cards are
  in the public state (`claim.hand`, on the table channel too).
- **Agreement:** every other non-dummy player must accept — both defenders
  for declarer's claim, declarer and the other defender for a defender's.
  One reject clears the claim and play goes on; the claimer may withdraw it
  while it is pending. There is no director: a disputed claim is simply
  rejected and played out.
- **Silence means no:** online, a player who doesn't answer would stall the
  table, so a claim not fully accepted within `bridge.claim_seconds` (10)
  **expires** and is rejected as a reject would (`ClaimService::expire()`,
  run by the queued `App\Jobs\ExpireClaim`); accepts already given don't
  count. The state shows the deadline as `claim.expires_at`, and from then
  on no answer is taken. The row lock decides a late answer racing the job.
- **While pending** no card may be played (409) and no other claim made;
  whose turn it is doesn't change.
- **Result:** the last accept ends the board through `BoardTable::finish()`,
  scored as usual (§6), with declarer's tricks = tricks won so far + the
  claimed share of the rest (`tricks` if the claimer is on declarer's side,
  the remaining tricks less `tricks` otherwise). The claim columns are kept,
  and the result shows `claimed: true`.
- A player leaving mid-claim detaches the playing like any unfinished board.
- `TableSeeder` seeds one table ended by declarer's accepted claim, and
  `DatabaseSeederTest` replays it.

Not built yet / open questions:
- Undoing a card played by mistake.
- A claim is all-or-nothing: no director to rule on a disputed one, and no
  stated line of play attached to it.

## 6. Scoring (duplicate)

Let *level* be the contract level and *tricks* the number declarer won.
Contract made if `tricks >= level + 6`.

### Contract made

**Trick points** (for bid tricks only; doubled ×2, redoubled ×4):

| Strain | Per trick |
|---|---|
| ♣ ♦ (minors) | 20 |
| ♥ ♠ (majors) | 30 |
| NT | 40 first trick, 30 each after |

If trick points ≥ **100** the contract is a **game**, otherwise a **part-score**.
Game = 3NT, 4♥/4♠, or 5♣/5♦ (or lower if doubled).

| Bonus | Not vulnerable | Vulnerable |
|---|---|---|
| Part-score | 50 | 50 |
| Game | 300 | 500 |
| Small slam (level 6) | +500 | +750 |
| Grand slam (level 7) | +1000 | +1500 |
| "Insult" for making a doubled contract | 50 | 50 |
| "Insult" for making a redoubled contract | 100 | 100 |

**Overtricks** (each trick over the contract):

| | Not vulnerable | Vulnerable |
|---|---|---|
| Undoubled | trick value (20/30) | trick value (20/30) |
| Doubled | 100 | 200 |
| Redoubled | 200 | 400 |

### Contract defeated (undertricks, scored by defenders)

| Undertrick | NV undoubled | NV doubled | NV redoubled | V undoubled | V doubled | V redoubled |
|---|---|---|---|---|---|---|
| 1st | 50 | 100 | 200 | 100 | 200 | 400 |
| 2nd and 3rd, each | 50 | 200 | 400 | 100 | 300 | 600 |
| 4th and more, each | 50 | 300 | 600 | 100 | 300 | 600 |

Examples: 4♠ making 5, vulnerable → 120 + 30 + 500 = **650** (just made:
**620**). 3NT doubled, not vulnerable, down 2 → 100 + 200 = **300** to
defenders (down 3: 100 + 200 + 200 = **500**).

### Comparing results across tables (duplicate only)

The raw score above is then compared with other tables that played the same
board:
- **Matchpoints** (pairs events): for each other result on that board, 2 pts
  if you beat it and 1 if you tied. Example: N-S scores 620, 620, 170, −100
  get 5, 5, 2 and 0 out of a top of 6; E-W get the top minus N-S's.
- **IMPs** (teams events): the score difference is converted to International
  Match Points on a fixed scale.

### Rubber bridge (for reference, not modelled)

Scores build up over several hands until one side wins two games (a
"rubber"). Trick points go "below the line" towards game. Rubber bonus:
700 if won 2–0, 500 if won 2–1. Honours bonus: 100 for 4 of the 5 top
trumps in one hand, 150 for all 5, or 150 for all 4 aces in NT.

**In code:** `App\Services\ScoringService::score($contract, $doubled,
$declarerSeat, $vulnerable, $tricksWon)` is a pure function (no DB,
unit-tested in `tests/Unit/ScoringTest`) implementing the tables above —
trick points, part-score/game/slam bonuses, the insult, overtricks and
undertricks — and returns the score from **declarer's** side (negative when
defeated). Vulnerability is the declaring side's, read from
`boards.vulnerable` (`Vulnerability::BOTH` makes both sides vulnerable).
`BoardTable::finish()` is the one place a board ends: it stores `tricks_won`
(declarer's side), `score` **from N-S's point of view** (negated when E-W
declared) and `finished_at` from the contract (`contract_bid_id` + `doubled`)
and `declarer_seat`. A passed out board gets `score = 0` and `tricks_won`
null. An accepted claim (§5) is scored the same way, with the claimed
tricks. The game state shows it as `result` (`{contract, doubled, declarer,
tricks_won, score_ns, made_by, claimed}`). Honours and rubber scoring aren't modelled;
matchpoints across tables are computed by `ScoringService::matchpoints()`
and served by `GET /boards/{board}/results` (§8 step 7); IMPs aren't built.

## 7. Glossary

| Term | Meaning |
|---|---|
| Board | One fixed deal, with its dealer and vulnerability; can be replayed at many tables |
| Call | Any auction action: a bid, Pass, Double or Redouble |
| Strain | The trump suit of a bid, or NT |
| Contract | The final bid, plus X/XX if doubled or redoubled |
| Declarer | The player who plays the contract, from both their own hand and dummy's |
| Dummy | Declarer's partner; their cards are face up and they take no part in the play |
| Defenders | The two opponents of declarer |
| Opening lead | The first card of the play, by the player to declarer's left |
| Trick | 4 cards, one per player; won by the highest trump or highest card of the suit led |
| Book | The first 6 tricks, which bids don't count |
| Vulnerable | A status set by the board that raises bonuses and penalties |
| Part-score / Game / Slam | Contract worth <100 trick points / ≥100 / level 6 or 7 |
| Passed out | All four players pass; no play |
| Claim / Concession | Stopping the play by stating how many of the remaining tricks your side will take (a concession: none); the opponents accept or dispute it |
| Matchpoints / IMPs | Duplicate methods for comparing scores across tables |

## 8. Game-flow checklist for implementers

1. Create a table and assign a board (which gives the dealer and
   vulnerability): set `tables.board_id`, create the `board_table` row and
   copy the four seats into `board_table_seats`. Choose the board with the
   selection rule below.
2. Four users take the N/E/S/W seats (`table_seats`): the creator is seated by
   `POST /tables`, the others join with `POST /tables/{table}/seats`, or are
   seated by a table manager with `POST /tables/{table}/seats/users`. The
   database enforces one user per seat and one table per user. Joining while
   already seated **moves** the player — the old seat is freed in the same
   request — so one table per user holds without the client having to leave
   first. A table is active while somebody sits at it (`Table::active()`
   scope); `DELETE /tables/{table}/seats` frees a seat, and the last player out
   deletes the table. Once seated, each player says they are ready to play
   (**Start**); the board of step 1 is dealt when the table is full and every
   human there has pressed it — robots are always ready.
3. The auction starts at the dealer and goes clockwise. Validate each call
   and store it in `auctions`. Stop after 3 passes following a bid, or after
   4 initial passes (passed out).
4. Work out the contract, declarer and dummy, and save them on `board_table`.
5. Play: declarer's left-hand opponent (`Seats::next(declarer_seat)`) leads
   first, then dummy is revealed. Validate each card and store it in
   `cardplays` (`round`/`order` incremented by the card-play API). After the
   4th card, set `won_trick` on the winner, who leads next. Any player but
   dummy may instead claim some of the remaining tricks (0 concedes); once the
   other non-dummy players all accept, the board ends with the claimed tricks.
6. After 13 tricks (or an accepted claim), count declarer's tricks, score
   them (section 6) and store `tricks_won`, `score` and `finished_at` on
   `board_table`. Once the players have had a few seconds to see the
   result, pick the table's next board — unless it was the last board of
   the set (below), which waits for everyone's Start.
7. Compare across tables that played the same board (matchpoints/IMPs).

### Board-selection rule

Boards should change as often as possible so players never recognise the
cards if they meet a deal again. When a table needs its next board:

1. Prefer a board that **none of the four players has played** (no
   `board_table_seats` row for that user on that board) and that this table
   hasn't played. Only the **human** players count: robots don't remember
   deals, and a shared robot pool would soon have played every board.
2. If every board has been played, pick one where **no player has held the
   seat they are sitting in now**. Example: North played board 10 as N. If they
   are N again, board 10 is skipped; if they are now E, board 10 is allowed.
3. A table can never replay a board (`unique(board_id, table_id)`).

The data for this is `board_table_seats` (index on `user_id, seat`). It
survives the table being deleted: `board_table.table_id` is nullable and
`nullOnDelete`, so a player's board history stays queryable after the table
they played at is gone.

**In code:** `App\Services\BoardSelectionService::startIfReady()`, called by
`start()` (`POST /tables/{table}/start`) and by `TableSeatService::seat()`,
which deals once the table is full and every seat's Start is set
(`table_seats.ready_at`; robots' from the moment they sit down). It applies rule
1, then rule 2 — both over the human players' history only, robots being
left out (§9) — and if neither leaves a candidate it **deals a brand-new
board** rather than repeating one — boards are only shuffled deals, so the app
never has to hand a table a deal somebody at it already knows. A player
leaving before the board is finished detaches the playing
(`abandonPlaying()`), which keeps the seat snapshot but frees the table for
another board; the calls and cards made so far are discarded, since an
abandoned board has no result to review. A **finished** playing keeps its
calls and cards even after its table is deleted, so any player who has
finished the board can replay it call by call and trick by trick
(`GET /playings/{playing}`); playings finished before that change lost them.

### Sets of boards

Play at a table goes in **sets** of `bridge.set_size` boards (4, env
`BRIDGE_SET_SIZE`), the same four players in the same seats throughout:

1. Everybody at a full table presses **Start** → the first board of a new
   set is dealt.
2. After each board, its result stays on show for
   `bridge.next_board_seconds` (10, env `BRIDGE_NEXT_BOARD_SECONDS`) → then
   the set's next board is dealt by itself, by the selection rule above.
   Every human asking for it (`playing/next`; robots count as asking) deals
   it at once instead.
3. After the set's last board the set is **over** (`ended: completed`):
   nothing is dealt by itself, Next is refused with
   `"The set is over: press Start for a new one."`, the finished board stays
   on show, and the players see the set's result.
   Playing on takes everybody's Start again, which opens the next set
   (numbered on at the table, 1, 2, 3…).

A set's **result** adds up its boards' scores from N-S's side; the side
with the higher total wins it, a tie has no winner. Each board also carries
its matchpoints against every other table that has played it, and the set
totals them, but matchpoints don't decide the set: a board only this table
has played has nothing to be compared with.

**Going away costs the set.** If a player leaves or stops responding during
a set (a board in progress, or between boards of an unfinished set), their
partnership **loses the set** — but only after **3 minutes**
(`BRIDGE_SET_FORFEIT_MINUTES`); if they come back in time, play goes on:

- A human with no sign of life (heartbeat or playing request) for a minute
  (`BRIDGE_AWAY_SECONDS`, 60) is **away**: their seat is held — nobody else
  can take it — and the board simply waits on them. The others see it, with
  a countdown (`away_since`, `forfeit_at` per seat).
- Any sign of life before the deadline brings them back: play continues
  where it was.
- Still away 3 minutes after their last sign of life, their side (`NS` or
  `EW`) **forfeits** the set (`ended: forfeit`, `forfeited_by`): the other
  side wins it, whatever the scores so far. The board in progress is
  abandoned unscored (detached, as on any leave), the away player's seat is
  freed through the normal leave path, and everyone sees the set's result.
- Pressing **Leave** mid-set counts as going away (held for 3 minutes, they
  may come back). **Moving** to another table mid-set forfeits at once, and
  so does a manager **kicking** a player who is away.
- Robots are never away. A human whose partner is a robot forfeits for that
  side the same way.
- **Admins** never cost their side the set: an absent admin is shown away
  but has no deadline and their seat is never freed for it, and the table
  just waits. While an admin is away nobody forfeits at all: the others'
  countdowns stop and they may Leave (or move) at once without penalty —
  the set ends `abandoned`. An admin's own Leave is immediate too.
- Once the set is over (completed, forfeited or abandoned), anyone still
  away is no longer held for it: their seat is freed (an admin's is kept).

A set also ends **early**, with no winner, when one of its four players is
taken out of the table before its last board is finished without it being
a forfeit (`ended: abandoned`): a kick of a player who is there, leaving
while an admin is away, an admin leaving. The board in play is abandoned as
before (detached); the next board waits for everyone's Start and opens a
new set. Outside a set nothing of this applies: Leave is immediate and the
usual idle timeout (`BRIDGE_IDLE_SEAT_MINUTES`) frees a quiet player's seat
(never an admin's: only the admin or another admin may take that one).

**In code:** `table_sets` (+ `table_set_seats`, the four players) and
`board_table.table_set_id`/`set_position`. `BoardSelectionService::deal()`
opens a set on a Start (`openSet()`) and continues it on Next;
`BoardTable::finish()` completes the set when its last board ends;
`TableSeatService::remove()` calls `BoardSelectionService::abandonSet()`,
or `forfeitSet()` first when the player going is away or moving tables
mid-set (`costsTheSet()` holds the admin exceptions).
`TableSeatService::leave()` holds the seat on a mid-set Leave
(`table_seats.away_since`), `touch()` clears it on any sign of life, and
`checkAway()` (`tables:check-away`, scheduled every ten seconds) marks
quiet players away, forfeits for the one away too long and frees those
still away once the set is over.
`moveOn()` refuses after the last board, and `start()` accepts a Start once
the set is over even with the same four seated. A set outlives its table,
like the playings in it. Its results are `GET /sets/{set}`
(`BoardResultsService::set()`); the game state and table payloads carry
`set: {id, number, board, of, finished, ended, forfeited_by}`.

Status today: the data layer for steps 1–6 exists (migrations, models,
seed data played through the game services, the seating unique indexes, board dealer and vulnerability
helpers, `board_table` history, the saved-contract columns and `won_trick`).
Over HTTP:
- **Step 1 is built**, but it happens once the table is *full and everyone
  has pressed Start*, not when it is created — the selection rule needs all
  four players' history, and `board_table_seats` snapshots four seats, so
  neither is knowable at `POST /tables` — and not the moment the fourth seat
  is taken either, so nobody is thrown into an auction before reaching the
  table. `POST /tables` (with robots or not) leaves `board_id` null; each
  human presses `POST /tables/{table}/start`, and the last Start of a full
  table (or a robot filling the last seat once every human has pressed it)
  triggers `BoardSelectionService::startIfReady()`, which picks or deals a
  board, sets `tables.board_id`, creates the `board_table` row and copies the
  four seats into `board_table_seats`. Start belongs to the seat: leaving,
  moving, a kick or an idle release drop it, and dealing clears it. Nobody
  can press it for anyone else.
- Step 2 is built as far as seating goes. The creator is seated by
  `POST /tables`, others join with `POST /tables/{table}/seats`, and anyone
  leaves with `DELETE /tables/{table}/seats` — all through `TableSeatService`,
  which enforces a valid free seat and one table per user. Leaving hands
  `moderated_by` (if the leaver had it) to the remaining human seated there
  longest, and the last player out deletes the table. A table manager
  can seat another user (`POST /tables/{table}/seats/users`) or a robot
  (`POST /tables/{table}/seats/robots`, §9), or kick a player
  (`DELETE /tables/{table}/seats/{user}`); a kick is not recorded, so the
  player may rejoin at once.
- Once dealt, the board can be **seen**: `GET /tables/{table}/playing`
  (`PlayingStateService`) gives each seated player the phase, board number,
  dealer, vulnerability, the four players from the `board_table_seats`
  snapshot, whose turn it is, and **only their own** hand (13 cards less any
  played) — plus dummy's, face up to everyone, once the opening lead is made. At the deal, `PlayingUpdated` pushes the public part on the table
  channel and `HandDealt` sends each player their cards on their own user
  channel. Phase is `auction` until `auction_ended_at` is set, then `play`
  until `finished_at`, then `finished`.
- **Steps 3–4 are built**: `POST /tables/{table}/calls`
  (`AuctionService`) takes each call in turn, refuses illegal ones with a 409
  giving the reason, and once the auction ends saves the contract, `doubled`,
  declarer and `auction_ended_at` on `board_table`. The state's `auction`,
  `turn` and `contract` (with dummy = declarer's partner) follow it, and every
  call is pushed as `PlayingUpdated`. A passed out board is finished
  straight away with a score of 0.
- **Step 5 is built**: `POST /tables/{table}/cards` (`CardPlayService`)
  takes each card in turn — declarer playing dummy's — refuses illegal ones
  with a 409 giving the reason (wrong turn, dummy's own player, a card not in
  that hand or already played, not following suit), sets `won_trick` on each
  trick's winner, who leads next, and pushes every card as `PlayingUpdated`.
  The state gains `acting_user_id`, `tricks`, `current_trick`, `tricks_won`
  and `dummy_hand`. **Claims and concessions are built too**
  (`ClaimService`, `/tables/{table}/claim`): any player but dummy claims,
  the other non-dummy players accept or reject, the claimer may withdraw; no
  card is played while a claim is pending, whose hand is shown as the state's
  `claim`. An accepted claim finishes the board with `result.claimed: true`.
  Undoing a card is not built.
- **Step 6's scoring is built**: after the 13th trick (or an accepted claim)
  `BoardTable::finish()` saves `tricks_won`, the §6 `score` (N-S's side,
  from `ScoringService`) and `finished_at`; the phase becomes `finished` and
  the state gains `result`.
- **Step 6's "pick the table's next board" is built**: a finished board
  (played out, claimed or passed out) stays on the table, whole deal shown,
  for `bridge.next_board_seconds` (10; the state's `next_board_at`), and then
  the queued `App\Jobs\DealNextBoard` (`BoardSelectionService::dealNext()`)
  deals the next board with the same selection rule and the same four
  players in the same seats — even while one of them is away. Earlier, once
  every human has sent `POST /tables/{table}/playing/next` for themselves
  (nobody, not even a manager, asks for the others; robots count as asking),
  the last one deals it at once (`BoardSelectionService::moveOn()`). Either
  way it is dealt once, under the table's row lock. Leaving between boards
  detaches nothing and stops the timer; once the empty seat is filled, the
  four are no longer the board's four, so nothing is dealt by itself, Next
  is refused and everyone's Start deals the next board, as with the first
  one. **Sets of boards are built** (above): Next only deals within
  a set, and after its last board everyone's Start opens the next set.
- **Step 7 is built for matchpoints**: `GET /boards/{board}/results`
  (`BoardResultsService::results()`) lists every finished playing of a board
  — the four players, contract, declarer, tricks, N-S score — with each
  result's matchpoints for N-S and E-W against all the others
  (`ScoringService::matchpoints()`, pure and unit-tested: 2 per result
  beaten, 1 per tie, out of a top of 2 × (results − 1); E-W get top − N-S).
  They are worked out on every read, never stored, because every new playing
  changes everyone's. Only players who have **finished** the board may read
  them (or its deal, `GET /boards/{board}`) — anyone else may still be dealt
  it — and results from tables since deleted are kept. Each player's
  finished boards are listed by `GET /users/{user}/playings` (their own:
  `GET /api/user/playings`), and any finished playing of a board they have
  finished can be reviewed call by call and trick by trick with
  `GET /playings/{playing}`, since a finished playing's calls and cards now
  outlive its table. IMPs aren't built.

See [`API.md`](API.md).

## 9. Robots

Real bridge needs four players. So that one person can play (or test) alone,
a seat may hold a **robot**: `POST /tables` with `robots: true` fills the
other three seats at once, or a table manager fills any free seat with
`POST /tables/{table}/seats/robots`. A robot is ready to start from the
moment it sits down, so a human with three robots deals with their one
Start. A robot is a `users` row with
`is_robot`, from a pool of `robot-<n>` users.

Rules the robots keep, and that keep them honest:

- **The same rules as a human.** Every robot call, card, claim, claim
  answer and "ready for the next board" goes through `AuctionService`,
  `CardPlayService`, `ClaimService` and `BoardSelectionService::moveOn()`,
  so §4 and §5 are enforced on robots exactly as on people. Declarer's robot
  plays dummy's cards; a robot dummy does nothing, like a human dummy.
- **A human never just watches.** When a robot declares and its partner,
  dummy, is a human, the human plays **both** hands for the whole play —
  declarer's cards and their own — and claims, answers claims and withdraws
  for declarer's seat; the robot declarer never acts in the play. Nothing
  else changes: declarer and dummy are still the auction's
  (`contract.declarer`/`dummy`), declarer's left-hand opponent still leads,
  the human's hand still goes face up after the lead as dummy's, scoring
  and the history are the same, and each card is recorded by the hand it
  came from. Only the human sees declarer's cards (`declarer_hand` in their
  own state, pushed as `DeclarerHandShown` when the auction ends); the
  defenders see dummy only, as always. Playing for declarer is a sign of
  life like any card, and the away and forfeit rules (§8) apply to the human
  while either hand is to play. **In code:**
  `PlayingStateService::dummyPlaysForDeclarer()` (from the seat snapshot),
  `CardPlayService::actingSeat($turn, $declarer, $dummyPlays)` and
  `ClaimService::illegalPlayerReason(..., $dummyPlays)`. A human declarer
  with a robot dummy plays both hands as before.
- **Robots alert.** A robot alerts its conventional calls to the
  opponents, with its system's explanation, and answers a question about
  any of its calls at once (`RobotBidder::read()`, "Natural" for a call no
  rule makes), through the question endpoint or in the board's chat — the
  list is in [`ROBOTS.md`](ROBOTS.md#alerts). Robots don't otherwise
  chat.
- **No peeking.** A robot decides from what its own seat is served
  (`PlayingStateService::stateFor()`): its hand, dummy once face up, a
  claimer's face-up hand and the cards played — never the other hands.
- **A defender looks at dummy.** On lead after the opening lead, a robot
  defender doesn't lead a side suit dummy (or declarer, having shown out)
  will ruff — not even a master — nor give a **ruff-and-discard** (a suit
  both declarer and dummy are out of while either has a trump) unless
  partner's over-ruff surely beats the contract, nor lead **up to** a
  tenace in dummy (A-Q, K-J …) when dummy plays last; through it is fine.
  It prefers a suit dummy is **weak** in, else a **trump** when dummy is
  short in a suit the defence holds and could ruff it, and partner's
  signals still come first unless dummy now ruffs that suit. **In code:**
  `App\Robots\LeadSafety`, used by `DefenderPlay::lead()`; the full list
  is in [`ROBOTS.md`](ROBOTS.md#a-defender-on-lead-later).
- **Only with a human there.** Robots act only while at least one human is
  seated. When the last human leaves, the table is kept *unattended* and the
  robots wait; the first human to sit down runs it, and after 10 minutes
  without one it is deleted.
- **Humans are never replaced.** A human who leaves frees the seat; no robot
  takes it unless a manager puts one there.
- A robot never manages a table and never redoubles. It claims the rest
  only when every trick left is a top winner in the hand on lead (and
  never twice from the same point of the play), answers claims — double
  dummy in endings of six tricks or fewer — and asks for the next board as
  soon as one ends (it never holds it up: robots count as asking).
- **No trump only with their suits held.** A robot bids a natural no trump
  over the opponents only with a **stopper** (A, K-x, Q-x-x, J-x-x-x) in
  every suit they have bid naturally — its own, or one partner's no trump
  already promised — and, on its first no trump, a balanced hand (3NT may
  instead be semi-balanced with a good long minor). Its partner reads the
  promise ("♠ stopped") and may raise to 3NT on it; without the stopper it
  plays a minor fit or a part-score, or passes. **In code:**
  `RobotHand::hasStopper()`, `AuctionView::theirSuits()` (natural bids,
  not cue bids) and `BidMeaning::$stopped`.

How robots bid (a SAYC-style system — Standard American Yellow Card — with
Stayman, transfers, a strong 2♣, weak twos, takeout, negative and penalty
doubles, Blackwood and Gerber) and play (declarer counts winners and
losers and plans a line — drawing trumps, ruffing in dummy, cross-ruffing,
finesses, holding up, setting up long suits; defenders signal attitude,
count and suit preference and read partner's, and lead with dummy in
view — away from its ruffs and tenaces; the last four tricks are
searched double dummy over every layout of the unseen cards), and how they
claim and answer claims, is in
[`ROBOTS.md`](ROBOTS.md). **In code:** `app/Robots/` (the pure decision
classes; the bidding system is `BiddingSystem`) and
`App\Services\RobotService` (the pool, and one move at a time), driven by
the queued listener `App\Listeners\DriveRobots` after every
`PlayingUpdated`. Status: bidding, card play and claims implemented; what
they don't do yet is listed in
[`ROBOTS.md`](ROBOTS.md#what-robots-dont-do).

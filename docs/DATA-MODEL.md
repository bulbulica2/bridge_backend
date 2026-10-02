# Data Model

Migrations were edited in place on `7-fix-database` (the project hasn't
shipped). An existing local DB must be rebuilt with
`php artisan migrate:fresh --seed`; a plain `migrate` won't pick the changes up.

Contract bridge domain: a `Board` is one dealt hand; a `Table` is where
players sit and play boards; `Auction` records bidding, `Cardplay` records
the trick-taking phase. See [`API.md`](API.md) for which of these are
actually exposed over HTTP today, and [`GAME-RULES.md`](GAME-RULES.md)
for the bridge rules these tables model (auction legality, trick winners,
scoring). The auction (`AuctionService`), the play (`CardPlayService`) and
duplicate scoring (`ScoringService`) are enforced in code.

## Enums (`app/auxiliary/*.php`)

- **Suits** (`Suits::SUIT_NAME`): `C`=Clubs, `D`=Diamonds, `H`=Hearts,
  `S`=Spades. `ALL_SUIT_NAMES` adds `NT`=No Trump (used for bids, not cards).
- **Seats** (`Seats::SEATS`): `N`, `E`, `S`, `W` → North/East/South/West
  (clockwise). `Seats::dealerForBoard(int $number)` returns the dealer for a
  board number (1→N, 2→E, 3→S, 4→W, repeating).
- **Vulnerability** (`Vulnerability::VULNERABILITY_SEATS`): `N-S`, `E-W`,
  `N-S E-W`, `''` (none), also available as `Vulnerability::NS`, `EW`, `BOTH`,
  `NONE`. `Vulnerability::forBoard(int $number)` returns the standard 16-board
  cycle value (see
  [`GAME-RULES.md` §3](GAME-RULES.md#3-boards-dealer-and-vulnerability)).
  Both helpers throw `InvalidArgumentException` for numbers below 1.

## Entities

### User (`users`)
Fields: `name`, `username`, `email`, `password` (hashed), `description`,
`is_admin` (hidden from JSON, cast to boolean, not fillable; grants
`TablePolicy::manage` on every table), `is_robot` (boolean, default false,
indexed, cast to boolean, not fillable), standard Breeze fields
(`email_verified_at`, `remember_token`).
`is_robot` marks a **robot player**. Robots are ordinary `users` rows made
only by `App\Services\RobotService` (`forceFill`-style, since the column
isn't fillable): `name` `Robot <n>`, `username` `robot-<n>`, `email`
`robot-<n>@robots.invalid`, a random password nobody knows, and
`email_verified_at` set. They form a pool: the first robot that sits nowhere
is reused, and a new one is made when all are busy, so `unique(user_id)` on
`table_seats` still means one table per robot. Login refuses them whatever
the password, and registration refuses any username starting `robot-`, so
the numbering can't collide with a human. Scopes `User::robots()` and
`User::humans()`; `UserFactory::robot()` makes one in tests.
`description` (nullable text, fillable) is free-form profile text: readable by
any logged-in user through `GET /users/{user}` and in every table payload, and
writable by its owner through `PATCH /api/user` (max 1000 chars), along with
`name`. `email` is **not** in `$hidden` — the owner's own `GET /api/user`
needs it — so any payload showing a user to *other* players must go through
`App\Http\Resources\UserResource` (`id`, `name`, `username`, `description`,
`is_robot`) rather than the raw model.
Relations: `createdTables` (hasMany Table via `created_by`), `moderatedTables`
(hasMany Table via `moderated_by` — a user can moderate several tables: the
ones they made, plus any they inherit when a moderator leaves), `seats`
(hasMany TableSeat), `tables`
(hasManyThrough Table via TableSeat), `auctions`, `cardPlays`,
`playedSeats` (hasMany BoardTableSeat — boards the user played and from which
seat).

### Board (`boards`)
Fields: `number` (unsigned int, not unique), `dealer` (enum `Seats::SEATS`),
`vulnerable` (enum, `Vulnerability::VULNERABILITY_SEATS`). All three are
fillable.
Relations: `tables` (hasMany), `cards` (belongsToMany Card via `board_card`
pivot, pivot has `seat` — this is how a board's 52-card deal is assigned to
N/E/S/W), `auctions` / `cardPlays` (hasManyThrough BoardTable — every
table's calls and cards on this board), `plays`
(hasMany BoardTable — every table that played this board). `tables` means the
tables whose **current** board this is.
`BoardFactory` numbers each new board one past the highest `number` stored and
derives `dealer` and `vulnerable` from it with the helpers above. Overriding
`number` in a factory state keeps them consistent. The DB doesn't check that
`dealer`/`vulnerable` match `number`.
Boards are **dealt on demand**: `App\Services\BoardSelectionService::dealBoard()`
shuffles the 52 reference cards into the `board_card` pivot, numbering the
board from the same sequence. It needs `CardSeeder` to have run and throws a
`RuntimeException` if `cards` doesn't hold exactly 52 rows.
`BoardSeeder` calls it `BoardSeeder::INITIAL_BOARDS` (5) times as a head start,
and the production branch of `DatabaseSeeder` calls it 100 times; the API deals
more whenever board selection runs out. `TableSeeder` seats its tables through
`TableSeatService` and has the four players press Start
(`BoardSelectionService::start()`), so the last one deals it one of those
boards by the normal selection rule.
`BoardFactory` itself deals no hands, so a board made through a factory
(including the one `BoardTableFactory` — and through it `AuctionFactory` /
`CardplayFactory` — creates by default) has **no** `board_card` rows. Board
selection skips such boards — it only considers boards with a full 52-card
deal. The seeders don't go through those factories for boards, so a seeded
DB holds only dealt boards.

### board_card (pivot)
Fields: `board_id` (FK boards), `card_id` (FK cards), `seat` (enum
`Seats::SEATS`). Primary key `(board_id, card_id)`, so a card appears only
once per deal. Nothing checks that each seat gets exactly 13 cards.

### Card (`cards`)
Fields: `suit` (enum `Suits::SUIT_NAME` keys), `rank` (integer), `rank_name`
(nullable string, e.g. "Ace", "King").
Relations: `boards` (belongsToMany via `board_card`), `cardPlays` (hasMany).
Global static: all 52 cards live once in this table; a specific board's deal
is expressed via the `board_card` pivot, not by duplicating card rows.
Rank encoding (`CardSeeder`): `2`–`10` = pip value, `11` **skipped**,
`12`=Jack, `13`=Queen, `14`=King, `15`=Ace. Higher is better, but values
aren't contiguous. `rank_name` is `"2"`…`"10"`, `"Jack"`, `"Queen"`,
`"King"` or `"Ace"`.

### Table (`tables`)
Fields: `name` (string, nullable), `created_by` (FK users, nullable),
`moderated_by` (FK users, nullable), `board_id` (FK boards, nullable),
`unattended_since` (timestamp, nullable, indexed, cast to datetime). All are
fillable. There is **no** `closed_at` and no closed/archived state.
**Active table** = at least one `table_seats` row points at it; query with the
`Table::active()` scope. A table lives only while somebody sits at it: the last
player to leave deletes the row (`TableSeatService::remove()`).
**Attended table** = at least one **human** (`is_robot` false) sits at it;
`Table::attended()`. `Table::MAX_ACTIVE_PER_CREATOR` (3) caps how many
attended tables one user may have as `created_by`; `POST /tables` returns
409 past that.
`created_by` is fixed for the life of the table, which is what that limit
counts — a record only: it grants no rights over the table. `moderated_by` is
the table's one role. It starts equal to `created_by` and moves whenever the
current moderator leaves, to the remaining **human** seated there longest
(earliest `table_seats.created_at`, then `id`) — never a robot, and the
creator gets no preference: one who left and came back is a plain player.
Only the moderator and admins manage a table — see `TablePolicy::manage` in
[`AUTH.md`](AUTH.md#authorization).
**Unattended table**: when the last human leaves and robots remain, the row
is kept with `unattended_since` set to that moment and `moderated_by` null.
Its robots stop acting and anyone may kick them (`TablePolicy::kick`). The
first human to sit down clears `unattended_since` and becomes `moderated_by`
(`TableSeatService::seat()`); otherwise `tables:delete-unattended` deletes
it once `unattended_since` is older than `bridge.unattended_table_minutes`
(10), through `TableSeatService::deleteUnattendedTables()`, which detaches
any unfinished playing first like a leave. `unattended_since` is null on
every table a human sits at.
`POST /tables` creates tables with `created_by` = `moderated_by` = the creator
and `board_id` null. `board_id` is filled in later, once the table is full
and every seat's `table_seats.ready_at` is set (each human pressed Start;
robots are ready from the moment they sit down):
`BoardSelectionService::startIfReady()`, run by `start()` and by
`TableSeatService::seat()`, then deals a board and opens the `board_table`
playing. It goes back to null when a player leaves an unfinished playing.
`freeSeats()` returns the unoccupied seats (from the `seats` relation) in
`N, E, S, W` order; `App\Http\Resources\TableResource` appends it to every
table payload as `free_seats`.
Relations: `creator`, `moderator` (both belongsTo User), `board` (belongsTo),
`seats` (hasMany TableSeat), `auctions` / `cardPlays` (hasManyThrough
BoardTable — the calls and cards of the playings still attached to this
table), `boardPlays` (hasMany BoardTable — boards this table has played),
`sets` (hasMany TableSet, by `number`), `latestSet` (hasOne TableSet, the
highest `number`: the set it is on or finished last, which `TableResource`
shows as `set`; `Table::latestSetWithBoards()` eager-loads it with how many
boards it has dealt), `players` (hasManyThrough User via TableSeat — marked "not tested yet" in
code).
`board_id` is the board being played **now** — null until the table has all
four players and they have all pressed Start, and null again once one of
them leaves before the board is finished. Past boards are in `board_table`.
A table's playings, and a **finished** playing's `auctions` and `cardplays`,
outlive it: the logs hang off `board_table`, which outlives the table, so no
foreign key cascades them, and they are kept so the board can be reviewed
(`GET /playings/{playing}`). Only an **unfinished** playing loses its logs:
the `Table::deleting` model hook discards those of any unfinished playing
still attached. In practice there is none by then — the last player leaves
through `TableSeatService::remove()`, which has already detached the
unfinished playing and discarded its logs (`abandonPlaying()`) — so the hook
is a guard for a table deleted some other way. Playings finished before
branch `34-board-review` lost their logs with their table. The same hook
ends any unfinished set still attached as `abandoned` (likewise normally
done already by `remove()`); sets themselves outlive the table.
`TableFactory` sets a fake company `name` and gives `created_by` and
`moderated_by` two different users by default.

### TableSeat (`table_seats`)
Fields: `table_id` (FK, cascade delete), `user_id` (FK, cascade delete),
`seat` (enum `Seats::SEATS`), `last_seen_at` (timestamp, indexed, defaults
to the current time; cast to datetime — also set by a `creating` hook, so a
fresh seat is never idle), `ready_at` (nullable timestamp, cast to datetime:
when the seat's player pressed Start, `POST /tables/{table}/start`; a robot's
is set as it sits down, a human's starts null and is cleared again by a seat
change at the same table and by every deal; `TableSeatResource` shows it as
`ready`). This is the "who is sitting where at this table" join table. All
five are fillable. Leaving deletes the row, Start with it.
Unique indexes:
- `(table_id, seat)`: one user per seat at a table (so at most 4 rows per
  table).
- `(user_id)`: a user sits at one table at a time, across every table. This
  invariant is never relaxed; joining a second table **moves** the row rather
  than adding one (see `seat()` below).

A user who takes a seat while already holding one is moved, so a client never
has to leave and rejoin by hand, and a failed second step can never leave them
seated nowhere.

Breaking either one throws a `QueryException` with SQLSTATE `23000`.
Seats are managed through `App\Services\TableSeatService`:
- `seat(Table, User, string, ?User $by = null)` checks the seat name is valid
  and the seat is free, and throws `App\Exceptions\SeatUnavailableException`
  if not. A unique-index `23000` from a concurrent request becomes the same
  exception. Everything happens in one transaction, so a refused move leaves
  the user on the seat they already had.
  - The user holds no seat: the row is created.
  - The user holds a seat at **another** table: they are moved. The old seat
    goes through `remove()`, so all of its consequences still happen — the old
    table is deleted if that was its last player, `moderated_by` is handed on,
    and `BoardSelectionService::abandonPlaying()` detaches an unfinished
    playing.
  - The user holds a seat at **this** table: the existing row's `seat` is
    updated in place. It is not deleted and re-created, so a table with one
    player isn't destroyed underneath them and the row keeps its `created_at`,
    which is the join order the moderator handover reads. A human's
    `ready_at` is cleared: Start belongs to the seat.
  - A new row gets `ready_at` set for a robot and null for a human, then
    `BoardSelectionService::startIfReady()` runs: a robot filling the fourth
    seat after every human pressed Start deals the board; a human sitting
    down never does.
  - `$by` is the acting user when that isn't the user being seated. A manager
    may only seat somebody who sits nowhere: pulling a player off a table they
    chose would abandon that table's board for three other people, so it stays
    a 409. A manager naming themselves counts as asking for themselves and is
    moved normally.
  - Both tables involved are locked up front, lowest `tables.id` first, so two
    players swapping tables at the same moment queue rather than deadlock.
- `remove(Table, User, ?User $by = null)` deletes the user's seat row and
  returns whether the table was deleted. It serves both quitting and being
  kicked. If no seats remain the table is deleted; otherwise, if the removed
  player was `moderated_by`, the role passes to the earliest remaining
  **human** seat (ordered by `created_at`, then `id`), whoever made the
  table. Throws `SeatUnavailableException` if the user
  holds no seat there; `$by` (the acting user, when it isn't the user being
  removed) only switches the message between "You are not seated at this
  table." and "That user is not seated at this table."
  Being kicked is not recorded anywhere, so a kicked player may immediately
  rejoin that table or any other.
- `touch(Table, User)` sets the user's `last_seen_at` at that table to now
  (a no-op if they don't sit there; `updated_at` is left alone). Called by
  `POST /tables/{table}/heartbeat` and by the `seen` middleware on the
  playing endpoints. `seat()` also refreshes it when a player changes seat.
- `releaseIdleSeats()` frees, through `remove()`, every seat whose
  `last_seen_at` is older than `config('bridge.idle_seat_minutes')`
  (`BRIDGE_IDLE_SEAT_MINUTES`, 5), or `bridge.idle_playing_seat_minutes`
  (`BRIDGE_IDLE_PLAYING_SEAT_MINUTES`, 15) while the table has an unfinished
  playing (`BoardSelectionService::openPlaying()`). Each seat is re-checked
  after its table row is locked, so a heartbeat, move or leave that lands
  in between wins. Returns how many seats it freed. Run every minute by the
  scheduled `tables:release-idle-seats` command.

Relations: `table`, `user` (both belongsTo).
`game\TableSeeder` creates each seeded table the way `POST /tables` does and
seats its players through `TableSeatService`: five full tables (one per phase
— see `RUNNING.md`) and one with two players. No seeded table is empty.

### Bid (`bids`)
Fields: `suit` (string — a call like "1H", "P", "X"), `suit_name` (string,
human label), `special` (boolean, default false — true for Pass/Double/
Redouble), `level` (unsigned tinyint, nullable — 1–7), `strain` (enum
`array_keys(Suits::ALL_SUIT_NAMES)`, nullable — `C`, `D`, `H`, `S`, `NT`).
All five are fillable; `special` casts to boolean and `level` to integer.
Relations: `auctions` (hasMany).
Note: this is a static reference table of possible calls (seeded via
`BidSeeder`), not a per-game record — the per-game record is `Auction`.
The 38 seeded rows:
- `P` "Pass", `X` "Double", `XX` "Redouble" — `special = true`, and `level`
  and `strain` both **null**: these calls have no rank of their own.
- `1C` "1 Clubs", `1D`, `1H`, `1S`, `1NT` "1 No Trump", `2C` … `7NT` —
  `special = false`, each with its `level` and `strain` set.

**Ranking is `(level, strain)`, never `id`.** `Bid::isHigherThan(Bid)` compares
that pair, using `Suits::strainRank()` for the `C < D < H < S < NT` order
(taken from the declaration order of `Suits::ALL_SUIT_NAMES`). `Bid::rank()`
throws a `LogicException` for a special call, since Pass/Double/Redouble are
legal on their own terms rather than by outranking anything. Scopes
`Bid::contracts()` (the 35 rankable bids) and `Bid::specials()` (the other 3)
select the two groups. `isPass()`, `isDouble()`, `isRedouble()` (by the
`suit` code, constants `Bid::PASS`/`DOUBLE`/`REDOUBLE`) and `isContract()`
tell a single call apart.

The seeder does insert bids in rank order, so on a freshly seeded DB ids
happen to ascend with rank — but ids aren't pinned and nothing enforces the
insert order, so **no code should compare bids by `id`**.
Clients get the ids from `GET /bids` (`Game\BidController`, see
[`API.md`](API.md#get-bids)), which lists them by rank, never by id.

### Auction (`auctions`)
Fields: `board_table_id`, `user_id`, `bid_id` (all FK), `seat` (enum
`Seats::SEATS`).
Represents one call made by one user, at one seat, in one playing (a
`board_table` row, i.e. one board at one table) — a sequence of these rows is
the bidding history of that playing. The board and table are reached through
`boardTable`.
Call order is the row `id`. Rows made over the API
(`POST /tables/{table}/calls`) go through `AuctionService`, which enforces
turn order and call legality and, when the auction ends, saves the contract
and declarer on `board_table`. `AuctionSeeder` goes through it too: it plans
a random auction out of legal calls and makes all of it, or stops at a point
where a given seat is to call. `AuctionFactory` makes a random, non-legal call
(test filler) and defaults `board_table_id` to a fresh `BoardTable::factory()`.
`board_table_id` is `cascadeOnDelete`, but `board_table` rows are never
deleted, so the log is removed explicitly instead: with the table
(`Table::deleting`), or when its playing is abandoned
(`BoardSelectionService::abandonPlaying`). The contract it produced survives
on `board_table`.
Relations: `boardTable`, `user`, `bid` (all belongsTo).

### Cardplay (`cardplays`, model class `Cardplay`)
Fields: `user_id`, `board_table_id`, `card_id` (all FK), `seat` (enum
`Seats::SEATS` — the hand the card came from), `round` (integer — trick
number), `order` (integer — play order within the trick, 1-4), `won_trick`
(boolean, default false, cast to bool — true on the card that won its trick).
Relations: `user`, `boardTable`, `card` (all belongsTo).
`user_id` is who played the card, `seat` is whose hand it came from; they
differ when declarer plays from dummy. The winning row's `seat` leads the next
trick.
Unique indexes: `(board_table_id, card_id)` (a card is played once per
playing) and `(board_table_id, round, order)` (one card per trick
position). Like `auctions`, the card-by-card log is deleted explicitly with
the table or when its playing is abandoned, while the result it produced
survives on `board_table`.
`App\Services\CardPlayService` (`POST /tables/{table}/cards`) is the only
code that writes real rows: it validates turn, hand ownership and follow
suit, sets `round`/`order`, and sets `won_trick` on each trick's winner when
the 4th card is played. `CardplaySeeder` goes through it too, playing a
random legal card at a time, so seeded winners and scores are real.
`CardplayFactory` makes a random, non-legal row (test filler).

### BoardTable (`board_table`, model class `BoardTable`)
One **playing** of a board at a table: the board's history and the result.
Migration `2025_01_30_150000_create_board_table_table.php` (also creates
`board_table_seats`; it runs before `auctions` and `cardplays`, which point
at it).
Fields:
- `board_id` (FK boards), `table_id` (FK tables, **nullable**, `nullOnDelete`).
  **Unique `(board_id, table_id)`**: a table never plays the same board again;
  other tables can. Because tables are deleted rather than closed, `table_id`
  goes `null` when the table disappears instead of blocking the delete — the
  playing, its result and its `board_table_seats` snapshot outlive the table,
  which is what keeps a user's board history intact for board selection.
  (`NULL`s don't collide in a unique index, so several orphaned playings of the
  same board are fine.)
- Auction result, null until the auction ends: `contract_bid_id` (FK bids,
  the final bid), `doubled` (tinyint: 0 none, 1 X, 2 XX, default 0),
  `declarer_seat` (enum `Seats::SEATS`), `declarer_id` (FK users). Passed out
  = `auction_ended_at` set with `contract_bid_id` null.
- `tricks_won` (tinyint, declarer's side), `score` (int, N-S perspective:
  negated when E-W declared), both nullable. Both are written, together with
  `finished_at`, only by `BoardTable::finish()` when the board ends: after
  the 13th trick (`CardPlayService`) with the tricks and the §6 duplicate
  score from `ScoringService::score()`, when a claim is accepted
  (`ClaimService`) with the tricks won so far plus the claimed share, or when
  the auction passes the board out (`AuctionService`) with `score = 0` and
  `tricks_won` null. Null on an unfinished or abandoned playing.
- Claim (`GAME-RULES.md` §5), all nullable: `claim_seat` (enum
  `Seats::SEATS`, the claimer — never dummy), `claim_tricks` (tinyint, the
  tricks claimed for the claimer's side of those still to play; 0 is a
  concession) and `claim_accepted` (JSON list of the seats that have accepted,
  in seat order; cast to `array`). All null while no claim is pending. A
  claim is **pending** while `claim_seat` is set and `finished_at` is null
  (`hasPendingClaim()`); a rejected or withdrawn one is cleared
  (`clearClaim()`), and an accepted one is **kept** alongside `finished_at`,
  so the result can say the board ended by claim (`result.claimed`). A
  playing detached with a claim pending keeps its columns as they were.
- `table_set_id` (FK table_sets, nullable) and `set_position` (tinyint,
  nullable): the [set](#tableset-table_sets) the board was dealt in and its
  place there, 1 to the set's `size`. Set on every playing the services
  deal; null only for rows made outside them (factories). A detached
  playing keeps both.
- `started_at` (defaults to now), `auction_ended_at`, `finished_at`
  (nullable), timestamps.

Lifecycle (`App\Services\BoardSelectionService`):
- **Opened** when a full table's last Start is pressed (or a robot fills
  the fourth seat after every human pressed it) — as the first board of a
  new set — or by the last Next, as the set's next board: the row is created
  with `started_at`, `table_set_id` and `set_position`, and the four
  `table_seats` are copied into `board_table_seats`.
- **Abandoned** when any player leaves before `finished_at` is set. The row is
  **not** deleted — `table_id` is set to `null`, exactly as when a table is
  deleted. The seat snapshot has to survive, because those four players were
  dealt those hands and have seen them; board selection reads
  `board_table_seats` to avoid handing them the same deal again. Detaching also
  releases `unique(board_id, table_id)` so the table can be dealt a fresh
  board. Its `auctions` and `cardplays` **are** deleted: the board is never
  resumed and has no result to review.
- **Finished** playings (`finished_at` set) are the duplicate result and are
  never detached or deleted, and keep their `auctions` and `cardplays` for
  good, even once their table is deleted.
An unfinished playing is therefore the one row with this `table_id` and a null
`finished_at`; there is at most one at a time.

Relations: `board`, `table`, `tableSet`, `contractBid` (Bid), `declarer`
(User) (all belongsTo), `seats` (hasMany BoardTableSeat), `auctions` / `cardPlays`
(hasMany on `board_table_id`; eager-loadable). `discardLogs()` deletes both.
`BoardTableFactory` has `auctionEnded()` (needs bids seeded) and `finished()`
states, and makes playings with no set. `finish()` also completes the set
when the board is its last (`set_position` reaches the set's `size`). Rows are written by `BoardSelectionService` (a full table's Start or
moving on), `AuctionService`, `CardPlayService`, `ClaimService` and
`BoardTable::finish()`; the seeders go through the same services.

### BoardTableSeat (`board_table_seats`)
Snapshot of who sat where for a playing, kept after players leave the table —
and after the table itself is deleted, since `board_table.table_id` is
`nullOnDelete` rather than cascading.
Fields: `board_table_id` (FK, cascade delete), `user_id` (FK users), `seat`
(enum `Seats::SEATS`), `ready_at` (nullable timestamp, cast `datetime`: once
the playing is finished, when this player asked for the next board —
`POST /tables/{table}/playing/next`; the next board is dealt when all four
are set. Not to be confused with `table_seats.ready_at`, Start). All four
are fillable.
Unique `(board_table_id, seat)` and `(board_table_id, user_id)`; index
`(user_id, seat)` for board selection ("has this user played board B?",
"…from seat S?" — see
[`GAME-RULES.md` §8](GAME-RULES.md#8-game-flow-checklist-for-implementers)).
Relations: `boardTable`, `user` (both belongsTo).

### TableSet (`table_sets`, model class `TableSet`)
A **set** of boards the same four players play in a row at one table
([`GAME-RULES.md` §8](GAME-RULES.md#sets-of-boards)): everyone's Start opens
it with its first board, Next deals the others, and it is over after its last.
Migration `2025_01_30_140000_create_table_sets_table.php` (also creates
`table_set_seats`; it runs before `board_table`, which points at it).
Fields (all fillable):
- `table_id` (FK tables, **nullable**, `nullOnDelete`): a set outlives its
  table, like its playings. `number` (unsigned int): 1, 2, 3… at that table,
  **unique `(table_id, number)`**.
- `size` (tinyint): how many boards it has, `bridge.set_size` (4,
  `BRIDGE_SET_SIZE`) when it was opened — a set keeps it if the config
  changes.
- `started_at` (defaults to now), `finished_at` (nullable): set once the set
  is over, however it ended.
- `ended` (enum `TableSet::ENDINGS`, nullable): null while it goes on, then
  `completed` (`BoardTable::finish()` on its last board), `abandoned` (one of
  its four left before that: `BoardSelectionService::abandonSet()`, from
  `TableSeatService::remove()`) or `forfeit` — **planned** (#76), nothing
  writes it yet.
- `forfeited_by` (enum `TableSet::SIDES`: `NS`, `EW`, nullable): the side that
  lost by forfeit — planned with it, always null today.
- timestamps.
A table has at most one unfinished set at a time. Relations: `table`
(belongsTo), `seats` (hasMany TableSetSeat), `playings` (hasMany BoardTable,
by `set_position`). `end($ended)` closes it (a no-op once closed),
`hasPlayer($userId)`. No factory: sets are opened by
`BoardSelectionService` only.

### TableSetSeat (`table_set_seats`)
The set's four players by seat, copied from `table_seats` when it opened —
the same four as every playing in it, since a change of player ends the set.
Fields: `table_set_id` (FK, cascade delete), `user_id` (FK users), `seat`
(enum `Seats::SEATS`), all fillable, timestamps. Unique
`(table_set_id, seat)` and `(table_set_id, user_id)`, index `user_id`.
Relations: `tableSet`, `user` (both belongsTo). `GET /sets/{set}` lets these
four see the set's results (`TableSetPolicy::view`).

## Relationship summary

```
User ──< TableSeat >── Table           (a User may be a robot: is_robot)
  │                       └── board_id ──> Board (current board)
  └── (created_by / moderated_by on Table; never a robot)

Board ──< board_card (pivot, +seat) >── Card

Board ──< BoardTable >── Table        (history: one row per playing,
             │   │                     unique board+table; contract, result)
             │   ├── table_set_id ──> TableSet >── Table   (+set_position)
             │   │                      └──< TableSetSeat >── User
             │   ├── contract_bid_id ──> Bid, declarer_id ──> User
             │   ├──< Auction >── Bid, User       (one row per call)
             │   └──< Cardplay >── Card, User     (+seat, +won_trick)
             └──< BoardTableSeat >── User   (who sat where)
```

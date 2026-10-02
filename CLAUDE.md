# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# bridge_backend

Laravel 11 / PHP 8.2 backend for an online **contract bridge** app (4 players
at a table bid in an auction, then play 13 tricks on a pre-dealt board; the
schema is shaped for duplicate bridge). Uses Sanctum (SPA/cookie auth) and
MySQL. GitHub repo: https://github.com/bulbulica2/bridge_backend. Branches
are named `<issue-title-prefix>-<topic>` (e.g. `7-fix-database`, tracked by
the GitHub issue whose title starts with it) and merged to `main` via PR;
commit messages are prefixed with the branch name. When the work on an issue
is done (tests and `pint --test` pass, docs updated), commit, push and open
the PR against `main` straight away — don't stop to ask first. The PR title
is the commit subject, and the body says `Closes #<issue>`.

If you don't know bridge rules (auction legality, declarer/dummy, trick
winner, scoring), read `docs/GAME-RULES.md` before touching game
logic — it also maps each rule onto the tables below and lists what isn't
enforced yet.

## Commands

Local stack is XAMPP (MySQL on 3306, DB `bridge`, user `root`, no password).

```bash
php artisan serve --host=localhost  # API on http://localhost:8000 (see RUNNING.md "Local speed")
php artisan reverb:start          # websocket server on :8080 (live table updates)
php artisan queue:work --sleep=0.1  # sends queued broadcasts to Reverb, and moves the robots
php artisan schedule:work         # runs tables:release-idle-seats and tables:delete-unattended every minute
php artisan tables:release-idle-seats  # free idle players' seats once, by hand
php artisan tables:delete-unattended   # delete tables only robots have kept, once, by hand
php artisan migrate:fresh --seed  # rebuild DB with sample data
php artisan route:list            # actual registered routes
php artisan test                  # all tests (PHPUnit 11)
php artisan test --filter=RegistrationTest            # one class
php artisan test --filter=test_new_users_can_register # one method
vendor/bin/pint                   # format (Laravel Pint, pint.json)
vendor/bin/pint --test            # check formatting without changing files
```

- Tests run on in-memory sqlite (`phpunit.xml`), so they don't touch the
  MySQL `bridge` DB and don't need MySQL running. Keep migrations
  sqlite-compatible. `phpunit.xml` points `APP_CONFIG_CACHE` at a
  test-only path so a dev `config:cache` can't override it, and
  `Tests\TestCase::createApplication` fails every test (before
  `RefreshDatabase` migrates anything) unless the config is `APP_ENV=testing`
  on in-memory sqlite. Feature tests must extend `Tests\TestCase` to get that
  guard. `phpunit.xml` also sets a test-only `APP_KEY`, so no `.env` is needed.
- `AppServiceProvider::register` force-enables laravel-debugbar only when
  `APP_ENV=local` and `.env` doesn't say `DEBUGBAR_ENABLED=false`. Don't make
  it unconditional: `enable()` skips debugbar's own testing check, and the
  HTML it injects breaks `assertNoContent()`. It also overrides the config,
  which is why the provider has to check `DEBUGBAR_ENABLED` itself.
- Breeze auth is lightly customized: `/register` also requires `username`
  (NOT NULL, unique on `users`).
- Migrations are edited in place (nothing has shipped), so after pulling
  schema changes run `php artisan migrate:fresh --seed`; plain `migrate`
  won't see them.
- `composer dev` won't work: it runs `npm run dev`, but there's no
  `package.json` (only a leftover `package-lock.json`). Use `php artisan serve`.
- Code style: 2-space indentation (`.editorconfig`), including PHP, and
  Pint's `laravel` preset for everything else. Pint can't be told to indent
  with 2 spaces (it hardcodes 4; laravel/pint#230 was rejected), so
  `pint.json` turns off its three indentation fixers (`array_`, `statement_`,
  `method_chaining_indentation`). Pint therefore won't catch or fix bad
  indentation — keep it at 2 spaces yourself. Don't remove those rules: a
  plain `pint` run would reindent the whole codebase to 4 spaces.
- `phpunit.xml` sets `BROADCAST_CONNECTION=null`, so tests never need Reverb.
  Assert broadcasts with `Event::fake()`; to test a channel callback through
  `POST /broadcasting/auth`, switch to the `reverb` driver inside the test
  (see `TableBroadcastTest::useReverbBroadcaster`) — the `null` driver lets
  everyone in.
- Long-running `reverb:start`, `queue:work` and `schedule:work` keep old code
  loaded: restart them after changing PHP.

## Architecture

- **Routing** (`bootstrap/app.php`): game endpoints live in `routes/web.php`
  (not `api.php`) so they share the session/cookie stack; `routes/auth.php`
  (stock Breeze, API-only — no views) is `require`d from `web.php`.
  `routes/api.php` only has `GET`/`PATCH /api/user` (the caller's own record,
  email included; `UserController@update` edits `name`/`description`) and
  `GET /api/user/playings` (the caller's own board history). Sanctum's
  `EnsureFrontendRequestsAreStateful` is prepended to the api group.
- **Player identity**: `email` is not in `User::$hidden` (the owner needs it),
  so anything showing a user to *other* players goes through
  `App\Http\Resources\UserResource` (`id`, `name`, `username`, `description`)
  — never the raw model. `TableResource` does this for seats via
  `TableSeatResource`; `GET /users/{user}` (`UserController@show`) serves the
  same public profile (plus `is_robot`). `GET /users?search=`
  (`UserController@index`, `throttle:30,1`) finds up to 10 **human** users by
  `username`/`name` — never by email, never robots — and loads
  `withExists('seats as seated')`, which `UserResource` adds as `seated`
  only when the query loaded it (`whenHas`).
- **Controllers**: game controllers are in `app/Http/Controllers/Game/` and
  extend `BaseController`, whose `sendResponse($data, $message, $code)` and
  `sendError($message, $code, $errors = [])` both return
  `{status, message, data}` — use them for new endpoints. Validation goes in
  `app/Http/Requests/<Area>/` form requests. `TableController` has
  `index/store/show` and `TableSeatController` has `store`/`destroy`
  (join/leave a seat) plus `storeUser` (`POST /tables/{table}/seats/users`,
  a manager seats someone else) and `destroyUser`
  (`DELETE /tables/{table}/seats/{user}`, quit if it's your own seat,
  otherwise a kick, `TablePolicy::kick`) and `storeRobot`
  (`POST /tables/{table}/seats/robots`, a manager seats a robot), all behind
  the `auth` middleware. `POST /tables` takes `robots: true` to fill the
  other three seats with robots (it deals nothing). Both serialise a
  table through `App\Http\Resources\TableResource` (model fields plus
  `free_seats`), so every table payload has the same shape.
  `Game\TableStartController` has `store`/`destroy`
  (`POST`/`DELETE /tables/{table}/start`, press Start or take it back).
  `POST /tables`, `POST /tables/{table}/start`, `POST /tables/{table}/seats`
  and `POST /tables/{table}/seats/robots` also add the caller's game
  state as `playing`
  (`TableResource::withPlaying()` with
  `PlayingStateService::dealtStateFor()`, null with no board yet), so the
  request that deals a board needs no `GET .../playing` after it; no other
  table payload has the key, and `TableUpdated` must never get it (a hand).
  `Game\CallController@store` (`POST /tables/{table}/calls`) is the auction
  endpoint, `Game\CardPlayController@store` (`POST /tables/{table}/cards`)
  the card-play one (`Game\CardController` is the unrelated read-only
  `/cards` reference list), and `Game\ClaimController` the claim ones
  (`POST`/`DELETE /tables/{table}/claim`,
  `POST /tables/{table}/claim/response`); `Game\PlayingController@show`
  serves the game state and `@review` (`GET /playings/{playing}`) one
  finished playing after the fact; `Game\TableSetController@show`
  (`GET /sets/{set}`) a set's results.
  `Game\BidController@index` (`GET /bids`, public) lists the 38 calls in
  `PlayingResource::bid`'s shape, so clients learn the `bid_id`s they send;
  it orders them P, X, XX, then by `Bid::rank()`, never by id.
- **Seating**: all seat logic lives in `App\Services\TableSeatService`.
  `seat()` checks the seat is valid and free and turns a unique-index SQLSTATE
  23000 into `App\Exceptions\SeatUnavailableException`. Taking a seat while
  already holding one **moves** the player rather than failing:
  `unique(user_id)` is never relaxed, the old seat goes out through `remove()`
  (so the old table is deleted if it was the last player, moderation is handed
  on and an unfinished playing is detached), and a seat change at the *same*
  table updates the row in place instead, so the table isn't deleted under its
  only player. A manager (`$by` set to somebody else) may only seat a user who
  sits nowhere — that case keeps its 409. Both tables are locked lowest-id
  first so opposite moves can't deadlock. `POST /tables` is the exception: it
  still 409s a seated creator rather than moving them.
  `remove()` frees a seat whether the player quit or was kicked: it deletes
  the table if that was the last player, and otherwise passes `moderated_by`
  to the remaining **human** seated there longest (never a robot; the
  creator gets no preference). With only robots left it keeps the table
  **unattended** (`tables.unattended_since` set, `moderated_by` null): its
  robots stop, anyone may kick them, the first human to `seat()` there
  becomes moderator, and `tables:delete-unattended` (scheduled,
  `TableSeatService::deleteUnattendedTables()`) deletes it after
  `bridge.unattended_table_minutes` (10). `seat()` and `remove()` `refresh()`
  the table under its row lock, since who runs it may have changed.
  Controllers map the exception to `sendError(..., 409)` on
  `DELETE /tables/{table}/seats` and to 404 on
  `DELETE /tables/{table}/seats/{user}`, where the seat is named in the URL.
  Reuse the service for join/move instead of re-checking; both `seat()` and
  `remove()` take an optional `$by` (the acting user) for when it isn't the
  user being seated or removed, so the error names "that user". Being kicked
  isn't recorded anywhere — there is no ban list, so a kicked player can
  rejoin at once. Both also call into `BoardSelectionService` (below), so
  they mutate the passed-in `$table`'s `board_id`.
- **Idle seats**: `table_seats.last_seen_at` is a player's last sign of life.
  `TableSeatService::touch()` sets it, from `POST /tables/{table}/heartbeat`
  (`TableSeatController@heartbeat`, sent by the client every ~30 s) and from
  the `seen` route middleware (`App\Http\Middleware\TouchTableSeat`) on the
  playing endpoints; add `seen` to any new playing route. The scheduled
  `tables:release-idle-seats` command (`routes/console.php`,
  `App\Console\Commands\ReleaseIdleSeats`) calls `releaseIdleSeats()`,
  which frees stale **human** seats (robots are never idle) through
  `remove()` — so it is exactly a leave —
  after `config('bridge.idle_seat_minutes')` (5), or
  `bridge.idle_playing_seat_minutes` (15) while the table has an unfinished
  playing, re-checking each seat under its table lock. Reverb can't report
  disconnects back to Laravel, which is why this is a heartbeat and not a
  presence channel.
- **Boards**: `App\Services\BoardSelectionService` owns which board a table
  plays and when. Filling the table deals nothing by itself: each human
  presses **Start** (`start()`, `POST /tables/{table}/start`, sets
  `table_seats.ready_at`; `withdrawStart()` takes it back), a robot's
  `ready_at` is set as it sits down, and `startIfReady()` — run by `start()`
  and by every `seat()` — deals once the table is full and every seat is
  ready (never at a robots-only table). Dealing clears the humans'
  `ready_at`; leaving deletes the seat row and its Start with it, and a seat
  change at the same table clears it. Nobody presses Start for anyone else.
  Start 409s (`StartBoardException`) while a board is in its auction or play,
  or finished mid-set with the same four still seated (they use Next). Not at
  `POST /tables`, because the selection rule needs all four
  players' history and `board_table_seats` snapshots four seats. The deal
  (private `deal()`) applies
  the §8 rule (a board none of the four has played, else one where nobody
  holds a seat they've held on it, else `dealBoard()` shuffles a brand-new
  one — over the humans' history only, robots are ignored), sets
  `tables.board_id` and opens the `board_table` playing.
  Boards come in **sets** (`TableSet`, `table_sets` + `table_set_seats`,
  `bridge.set_size` = 4): a Start's deal opens a set (`openSet()`), Next
  deals its next board (`board_table.table_set_id`/`set_position`),
  `BoardTable::finish()` completes it on its last board, after which
  `moveOn()` 409s ("The set is over: press Start for a new one.") and
  `start()` accepts a Start with the same four seated; robots don't ask
  then. `remove()` ends an unfinished set as `abandoned` (`abandonSet()`),
  even between boards; `ended: forfeit`/`forfeited_by` exist for #76 but
  nothing writes them yet. Sets outlive their table; `PlayingResource` and
  `TableResource` show `set: {id, number, board, of, finished, ended}`.
  After a board finishes it stays on the table (the state then shows the
  whole `deal` and `ready`) until `moveOn()`
  (`POST /tables/{table}/playing/next`, `Game\PlayingController@next`)
  has marked all four snapshot seats' `board_table_seats.ready_at` — each
  player for themselves only (a stale client's `everyone` is ignored) — and
  then deals
  for the same four; it takes the table row lock
  like seat changes do, and 409s (`NextBoardException`) unless the board is
  finished and the same four sit in the same seats. Leaving between boards
  detaches nothing; once the seat is refilled, everyone's Start deals the
  next board.
  `abandonPlaying()` fires from `remove()`: an unfinished playing is
  **detached** (`table_id` set to null), never deleted, so the seat snapshot
  keeps recording that those four saw the deal. `dealBoard()` needs the 52
  seeded cards and throws otherwise; boards with no `board_card` rows are
  never selected.
- **Realtime**: Laravel Reverb (decision and setup in
  `docs/RUNNING.md`). `App\Events\TableUpdated` is the
  pattern later events copy: `ShouldBroadcast` (queued, so a Reverb outage
  fails a job, not the request) + `ShouldDispatchAfterCommit` (dispatched
  inside the seating transaction, sent only if it commits), payload
  snapshotted in the constructor as the same `TableResource` JSON the HTTP
  endpoints return (through `json_encode` — `resolve()` leaves nested
  resources as objects). `TableSeatService` dispatches it on every seat
  change except one that deleted the table, and `BoardSelectionService` on
  every Start pressed or taken back and every deal. Channels are in
  `routes/channels.php`: `table.{id}` admits players seated there. Channel
  auth is checked only at subscribe time, so a player who leaves stays
  subscribed — anything private to one player (their hand) must go on
  `App.Models.User.{id}`, never on the table channel.
  Dealing a board dispatches `PlayingUpdated` (table channel, the
  public game state only — no hand, no `my_seat`) and one `HandDealt` per
  human player (their own channel, their 13 cards) when it deals a board.
  `AuctionService` re-dispatches `PlayingUpdated` after every accepted call,
  `CardPlayService` after every accepted card and `ClaimService` after every
  accepted claim action.
- **Game state**: `App\Services\PlayingStateService` is the one place that
  works out a playing's phase (`waiting`/`auction`/`play`/`finished`, from
  `tables.board_id`, `auction_ended_at`, `finished_at`), the calls so far
  (`calls()`, by row id), the cards so far (`plays()`, by round/order), whose
  `turn` it is (`AuctionService::nextToCall()` in the auction,
  `CardPlayService::nextToPlay()` in the play), who acts for it
  (`actingUserId()` — declarer on dummy's turn), dummy's face-up
  `dummyHand()` and a seat's hand (its
  `board_card` rows less anything in `cardplays`, sorted S/H/D/C high to
  low). `PlayingResource` is the public part; `stateFor()` adds the caller's
  `my_seat` and `hand`. `GET /tables/{table}/playing`
  (`Game\PlayingController`) serves it to seated players only
  (`TablePolicy::play`). Extend these rather than recompute state elsewhere.
  The payload also carries `auction` (`{seat, bid: {id, call, level,
  strain, special}}` per call), `contract` (`{bid, doubled, declarer,
  dummy}` once the auction ends with a bid) and, once there is a contract,
  `tricks`, `current_trick`, `tricks_won` and `dummy_hand` (null until the
  opening lead), `claim` while one is pending, plus `result` once the board
  is finished. `dummy_hand` and `claim.hand` are public — they go on the
  table channel — because dummy and a claimer are face up; no other hand
  may.
- **Auction**: `App\Services\AuctionService::call()` runs one call in a
  transaction that `lockForUpdate`s the `board_table` row
  (`PlayingStateService::currentPlaying($table, lock: true)`), so concurrent
  calls queue. The bidding rules are **static** functions over a list of
  `['seat' => ..., 'bid' => Bid]` (`nextToCall`, `illegalReason`, `isOver`,
  `result`), unit-tested without a DB in `tests/Unit/AuctionServiceTest`.
  An illegal call throws `App\Exceptions\IllegalCallException`, which the
  controller maps to a 409 carrying the reason. Tell calls apart with
  `Bid::isPass()/isDouble()/isRedouble()/isContract()`. When the auction ends
  it saves `contract_bid_id`, `doubled`, `declarer_seat`, `declarer_id` (from
  the `board_table_seats` snapshot) and `auction_ended_at`; a passed out
  board is finished at once through `BoardTable::finish(null)` (score 0).
- **Card play**: `App\Services\CardPlayService::play()`
  (`POST /tables/{table}/cards`, `Game\CardPlayController`) follows the same
  pattern: one transaction with the `board_table` row locked, static rules
  over a list of `['seat' => ..., 'card' => Card]` plays (`nextToPlay`,
  `actingSeat`, `illegalReason`, `trickWinner`, `tricks`, `tricksWon`)
  unit-tested in `tests/Unit/CardPlayServiceTest`, and
  `App\Exceptions\IllegalPlayException` mapped to a 409. Declarer plays
  dummy's cards, so a `cardplays` row's `user_id` is the caller and its
  `seat` the hand the card came from; dummy's own user is always refused.
  After each 4th card the winner's row gets `won_trick`; after the 13th trick
  it calls `BoardTable::finish($tricksWon)`.
  Compare cards by `rank` (not contiguous: 11 is skipped), never by id.
  It refuses every card while a claim is pending.
- **Claims**: `App\Services\ClaimService` (`claim`, `respond`, `withdraw`)
  is the same pattern again, with `App\Exceptions\IllegalClaimException` as
  the 409 and static rules (`illegalPlayerReason`, `responders`,
  `remaining`, `tricksReason`, `declarerTricks`) unit-tested in
  `tests/Unit/ClaimServiceTest`. The pending claim is stored on
  `board_table` (`claim_seat`, `claim_tricks`, `claim_accepted` JSON list);
  `BoardTable::hasPendingClaim()` is `claim_seat` set and not finished, and
  `clearClaim()` wipes it on reject/withdraw. The last accept calls
  `finish()` with tricks so far plus the claimed share; the columns are then
  kept, which is what `result.claimed` reads. A claim doesn't change
  `turn()`/`actingUserId()`.
- **Robots**: `users.is_robot` players from a `robot-<n>` pool
  (`App\Services\RobotService::seatRobot()`, always with the asking human
  as `$by`, so a busy robot is never moved). They can't log in
  (`LoginRequest` adds `is_robot = false`), and registration refuses
  `robot-*` usernames. The queued listener `App\Listeners\DriveRobots`
  (auto-discovered, delay `bridge.robot_delay_seconds`) runs
  `RobotService::act()` after every `PlayingUpdated`: **one** robot move —
  call, card (declarer's robot plays dummy) or a claim of the rest when
  every trick left is a top winner (once per position: a `Cache::add()`
  key stops a re-claim after a rejection), claim answer, or ready for the
  next board — through the normal services, only while a human is seated
  and the table isn't unattended. The decisions are pure classes in
  `app/Robots/` (`RobotHand`, `RobotBidder`; `RobotCardPlayer` over a
  `PlayView`, with `DeclarerPlan`, `DeclarerPlay`, `DefenderPlay`,
  `Signals`, `Discards` and `Endgame`; `RobotClaims`; `DoubleDummy`, the
  exhaustive solver the last two use on small endings) over the robot's own
  `stateFor()` arrays — never another hand — unit-tested in
  `tests/Unit/Robots/`. `RobotSimulationTest` has four robots bid and play
  seeded deals against the first robots' card play, kept in
  `tests/Unit/Robots/Support/V1CardPlayer` as the baseline. The bidding system is the
  ordered rule list in `BiddingSystem` (`BidRule` → `BidMeaning`, over an
  `AuctionView`): a robot makes the first legal rule its hand fits, and
  every call is read back through the same rules — change a rule there,
  never a separate "what partner means" table. Tests run the queue on
  `sync`, so the listener trampolines (a nested run only queues its table)
  instead of nesting 52 deep past xdebug's limit; fake `PlayingUpdated` to
  hold robots back while setting a table up. What they bid and play is in
  `docs/ROBOTS.md` — update it with any change to `app/Robots/`.
- **Authorization**: `App\Policies\TablePolicy::manage` (auto-discovered) is
  `is_admin || moderated_by === user`: a table has one role, its moderator,
  and `created_by` grants nothing (a creator who left and came back is a
  plain player). Admins, seated or not, may also kick the moderator. Check it in
  the form request's `authorize()` so non-managers get 403 before validation
  (`AddUserToSeatRequest`, `AddRobotToSeatRequest`). `TablePolicy::kick`
  (`RemoveUserFromSeatRequest`) allows yourself, a manager, or anyone
  kicking a robot from an unattended table. Every HTTP table
  payload carries `can_manage` (the policy for the caller), so clients never
  mirror it; `TableUpdated` builds its `TableResource` `withoutViewer()`, since
  the request user there is whoever made the change. `is_admin` is shown only
  on the caller's own record (`User::toOwnArray()`, `GET`/`PATCH /api/user`).
- **Domain enums** are plain constant classes in `app/auxiliary/` (lowercase
  namespace `App\auxiliary`): `Suits`, `Seats` (`N,E,S,W`, clockwise),
  `Vulnerability`. Migrations build DB enum columns from these, so changing
  them requires a migration. `Seats::dealerForBoard(int)` and
  `Vulnerability::forBoard(int)` implement the standard 16-board cycle;
  `Seats::next()` (left-hand seat, opening leader) and `Seats::partner()`
  (dummy).
- **Static reference data vs per-game data**:
  - `cards` (52 rows) and `bids` (38 calls: `P`,`X`,`XX` then `1C`…`7NT` in
    rank order) are seeded once and never duplicated. Card `rank` is 2–10,
    then J=12, Q=13, K=14, A=15 (11 skipped). `Card` has no factory.
  - A bid's rank is its `level` (1–7) and `strain` (`C,D,H,S,NT`) columns,
    both null for `P`/`X`/`XX`. Compare with `Bid::isHigherThan()`, which
    uses `Suits::strainRank()`; `Bid::rank()` throws for a special call.
    **Never compare bids by `id`** — the seeder inserts them in rank order,
    but nothing enforces that. `Bid::contracts()` / `Bid::specials()` scope
    the two groups.
  - A `Board` is a deal with `number`, `dealer` and `vulnerable`
    (`BoardFactory` derives the last two from `number`); its hands are the
    `board_card` pivot (`seat` column, primary key `board_id+card_id`).
  - A `Table` has an optional `name` and points at one `board_id`. It is
    **active** while at least one `table_seats` row points at it
    (`Table::active()` scope) — robots alone keep it alive, as unattended
    (`unattended_since`). There is no closed/archived state: the last
    player to leave deletes the row. A user may hold at most
    `Table::MAX_ACTIVE_PER_CREATOR` (3) active tables as `created_by`.
    `created_by` never moves, which is why it is what that limit counts —
    only tables a human sits at (`Table::attended()`); `moderated_by` does
    move, to the earliest-joined human left when the current moderator
    leaves.
  - `table_seats` puts users in seats: `unique(table_id, seat)` and
    `unique(user_id)` — one user per seat, one table per user. Violations
    surface as `QueryException` SQLSTATE 23000. Leaving means deleting the
    row, which is how a user frees themselves to create or join elsewhere.
  - `auctions` = one row per call, `cardplays` = one row per card played
    (`round` = trick 1–13, `order` = 1–4, `seat` = hand the card came from,
    since declarer plays dummy's cards, `won_trick` = winning card of the
    trick), both keyed by `board_table_id` (the playing) + `user_id`; reach
    the board/table through `boardTable`. A card is played once per playing,
    and each round+order once. `BoardTable::auctions()`/`cardPlays()` are
    plain `hasMany`, so they eager-load; `Table`/`Board` reach them
    `hasManyThrough` `board_table`.
  - `board_table` (model `BoardTable`) = one playing of a board at a table:
    board history (`unique(board_id, table_id)` — a table never replays a
    board), the saved auction result (`contract_bid_id`, `doubled`,
    `declarer_seat`, `declarer_id`), `tricks_won`, `score` and timestamps.
    `board_table_seats` snapshots who sat where, for the board-selection
    rule in `docs/GAME-RULES.md` §8. `tables.board_id` is only the
    current board. Because tables are deleted rather than closed,
    `board_table.table_id` is nullable and `nullOnDelete`: a playing and its
    `board_table_seats` snapshot outlive the table, so a user's board history
    survives; an unfinished playing is detached the same way when a player
    leaves mid-board. A **finished** playing's call-by-call and card-by-card
    logs outlive the table too (they hang off `board_table`, so no FK
    cascades them), for `GET /playings/{playing}`. Only an unfinished
    playing loses them, through `BoardTable::discardLogs()`:
    `abandonPlaying()` discards a detached playing's logs, and a
    `Table::deleting` hook discards those of any unfinished playing still
    attached (normally none, since `remove()` has detached it first — the
    hook is a guard). Playings finished before this change lost their logs,
    so they review with `auction: []`/`tricks: []`. Delete tables through
    Eloquent (`$table->delete()`), not a query-builder delete, or the hook
    won't run.
- **Seeding** (`DatabaseSeeder`) branches on `APP_ENV`: `production` seeds only
  cards, bids and 100 dealt boards (through `BoardSeeder`, so they have
  hands); anything else also seeds a fixed admin user (`email@email.com` /
  `pass`, `UserSeeder::ADMIN_EMAIL`) and `game\TableSeeder`'s tables, one
  per phase (see `RUNNING.md`): the admin's own table left mid-auction on
  the admin's turn, another mid-auction, one mid-play, one finished, one
  finished by declarer's accepted claim (through `ClaimService`), one
  passed out and one short of players. Seeders are split between
  `database/seeders/game/` (namespace `Database\Seeders\game`) and the root
  seeders folder. Everything is played through the real services:
  `TableSeatService::seat()`, then `BoardSelectionService::start()` for
  each player (so the last Start deals the board),
  `AuctionSeeder` (plans a random auction from legal calls, then makes it
  through `AuctionService`, optionally stopping where a seat is to call) and
  `CardplaySeeder` (random legal cards through `CardPlayService`); `Bidding`
  is on the second board of its set and `Set over` has played a whole set,
  through `moveOn()`. They take
  a `Table` and are run with `callWith()` from `TableSeeder`, which wraps it
  all in `Event::fakeFor()` so seeding queues no broadcasts.
  `tests/Feature/Database/DatabaseSeederTest` runs `migrate:fresh --seed`
  and replays every seeded call and card through the rules.
- **Scoring**: `App\Services\ScoringService::score()` is a pure static
  function (duplicate scoring, §6) unit-tested in `tests/Unit/ScoringTest`;
  it returns the score from **declarer's** side. `BoardTable::finish()` is
  the only place a playing ends: it writes `tricks_won`, `score` (stored
  **from N-S's side**, negated when E-W declared) and `finished_at`; a passed
  out board gets `score = 0`, `tricks_won` null. `PlayingResource` shows it
  as `result` once `finished_at` is set (`PlayingResource::result()`, which
  the results endpoints reuse).
- **Results across tables**: `App\Services\BoardResultsService` reads
  finished playings back from `board_table` + `board_table_seats`, so
  nothing is lost when a table is deleted. `results()` serves
  `GET /boards/{board}/results` (`Game\BoardController`): every finished
  playing of a board with matchpoints from the pure
  `ScoringService::matchpoints()` — computed on every read, **never
  stored**, since each new playing changes everyone's. `set()` serves
  `GET /sets/{set}` (each finished board's result and matchpoints, totals,
  `winner` by total score), for the set's players or anyone who finished
  all its boards (`TableSetPolicy::view`). `history()` serves
  the paginated `GET /users/{user}/playings` and `GET /api/user/playings`
  (`UserController`). `GET /boards/{board}` shows the deal
  (`PlayingStateService::boardDeal()`), and `GET /playings/{playing}`
  (`Game\PlayingController@review`) one finished playing — auction, tricks,
  result, deal — as `PlayingResource::forReview()` (the live shape less
  `ready`); an unfinished playing is a 404. All three go through
  `BoardPolicy::view` (auto-discovered; for a playing, on its board): only
  a player who has **finished** that board (`hasFinished()`) — no admin
  override, since anyone else may still be dealt it.
- Board selection (`GAME-RULES.md` §8), the auction (§4: turn order, bid
  legality, X/XX, end of auction, contract and declarer), the play (§5:
  opening lead, declarer playing dummy, follow suit, trick winner, dummy
  revealed after the lead, tricks won, claims and concessions), duplicate
  scoring (§6) and
  matchpoints across tables are implemented, as is moving on to the next
  board; IMPs aren't.
- `AuctionFactory`/`CardplayFactory` default `board_table_id` to a fresh
  `BoardTable::factory()`, whose board has no `board_card` rows. Such boards
  are inert — board selection only considers boards with a full 52-card deal.
  Both factories make random, non-legal rows — test filler only. The seeders
  don't use them (they go through the services), so a seeded DB has only
  dealt boards and legal play.

## Update `docs/` in the same PR — every relevant change, not a follow-up

Docs for this backend live in `docs/` in this repo, so they're versioned and
reviewed with the code. The frontend (https://github.com/bulbulica2/bridge,
an Ionic Vue SPA with its own `docs/`) reads them from this repo's `main`.
Keep links inside `docs/` relative, and link to the frontend's docs with
GitHub URLs (`https://github.com/bulbulica2/bridge/blob/main/docs/…`).

| File | Update it when you change... |
|---|---|
| `API.md` | any route (add/remove/rename), a controller action's request params, or a response's JSON shape/fields |
| `DATA-MODEL.md` | a model's fillable fields, relations, casts, or a migration (new table/column, enum values, FK) |
| `AUTH.md` | auth routes/middleware, Sanctum config (`config/sanctum.php`), CORS config, or how a client is expected to authenticate |
| `RUNNING.md` | local setup/run steps, `.env` keys required to run the app, or seeders/commands needed to get a working local DB |
| `ARCHITECTURE.md` | a service, policy, event or channel, job, middleware, console command or schedule, seeder, or one of the gotchas it lists |
| `GAME-RULES.md` | you implement or change enforcement of a bridge rule (auction, play, scoring), or its "In code" / status notes become wrong |
| `ROBOTS.md` | anything a robot decides (`app/Robots/`), or when and how robots act (`RobotService`, `DriveRobots`) |
| `README.md` | a file is added to or removed from `docs/`, or its one-line summaries go stale |

Rules:
- Treat this as part of the same change, in the same turn — not a separate
  pass or a TODO. If you touch a route file, a controller, a migration, or a
  model's `$fillable`/relations, check whether `docs/` needs a matching edit
  before considering the task done. The doc edit goes in the same commit and
  PR as the code.
- If one logical change touches multiple files above (e.g. a new endpoint
  with a new model field), update all of the relevant docs together.
- Docs must reflect actual current behavior, never aspiration. Explicitly
  mark endpoints/fields as implemented vs. stubbed (exists but empty) vs.
  planned-but-not-built (e.g. commented-out migrations), the way the
  existing docs do — don't silently document something as working if it
  isn't wired up yet.
- If you're unsure whether a change is "doc-worthy," err toward updating —
  stale API docs are worse than a redundant edit.

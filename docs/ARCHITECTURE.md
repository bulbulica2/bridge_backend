# Architecture

How the backend's code is organised, for developers working on it. What the
API looks like from outside is in [`API.md`](API.md), the tables in
[`DATA-MODEL.md`](DATA-MODEL.md), the bridge rules the services enforce in
[`GAME-RULES.md`](GAME-RULES.md), and how to run it all in
[`RUNNING.md`](RUNNING.md).

## Layers

A request goes through the same layers everywhere:

```
routes/*.php
  → middleware (auth, seen, throttle)
  → controller            app/Http/Controllers/{Game,Auth,…}
  → form request          app/Http/Requests/<Area>/   (authorize() + rules())
  → service               app/Services/               (the rules and the writes)
  → models                app/Models/
  → resource              app/Http/Resources/          (the JSON shape)
```

- **Routes.** Game endpoints are in `routes/web.php`, not `api.php`, so they
  share the session/cookie stack the SPA authenticates with
  ([`AUTH.md`](AUTH.md)). `routes/auth.php` (Breeze, API-only, no views) is
  `require`d from `web.php`. `routes/api.php` has only the caller's own
  record (`GET`/`PATCH /api/user`) and board history
  (`GET /api/user/playings`); Sanctum's `EnsureFrontendRequestsAreStateful`
  is prepended to that group in `bootstrap/app.php`, which also registers
  the `seen` middleware alias.
- **Controllers** in `app/Http/Controllers/Game/` extend `BaseController`.
  Answer with its `sendResponse($data, $message, $code)` and
  `sendError($message, $code, $errors = [])`, which both return
  `{status, message, data}`. Controllers stay thin: they call a service and
  map its exceptions to HTTP codes.
- **Form requests** hold validation, and authorization where it depends on
  the table: checking the policy in `authorize()` gives a non-manager a 403
  before validation runs (`AddUserToSeatRequest`,
  `RemoveUserFromSeatRequest`).
- **Services** in `app/Services/` own every rule and every write. Reuse them
  instead of re-checking a rule in a controller (see [below](#game-services)).
- **Resources** fix the JSON shape so each thing looks the same wherever it
  appears: `TableResource` (every table payload, including the broadcast
  one), `TableSeatResource`, `PlayingResource` (the game state) and
  `UserResource`. Anything that shows a user to *other* players goes
  through `UserResource` (`id`, `name`, `username`, `description`), never
  the raw `User` model: `email` isn't in `$hidden`, because the owner needs
  it on `GET /api/user`.

## Domain types

- `app/auxiliary/` (lowercase namespace `App\auxiliary`) holds plain
  constant classes used as enums: `Suits`, `Seats` (`N, E, S, W`,
  clockwise, with `next()` and `partner()`) and `Vulnerability`.
  `Seats::dealerForBoard()` and `Vulnerability::forBoard()` implement the
  standard 16-board cycle. Migrations build the DB enum columns from these
  classes, so changing one needs a migration.
- `cards` (52) and `bids` (38) are reference data, seeded once. Card `rank`
  is 2–10, then J=12, Q=13, K=14, A=15 (11 is skipped), so compare cards by
  `rank`, and bids with `Bid::isHigherThan()` / `Bid::rank()`. **Never
  compare either by `id`**: the seeder happens to insert them in order, but
  nothing enforces it.

## Game services

Each part of the game has one service. The ones that act on a playing follow
the same pattern:

- one DB transaction that `lockForUpdate`s the `board_table` row
  (`PlayingStateService::currentPlaying($table, lock: true)`), so concurrent
  requests at the same table queue instead of racing;
- the rules as **static** functions over a plain list (of calls, of cards
  played), which the unit tests exercise without a database;
- an exception for an illegal action, which the controller turns into a 409
  carrying the reason;
- a `PlayingUpdated` broadcast after every accepted action.

| Service | Responsible for | Tests |
|---|---|---|
| `TableSeatService` | joining, moving, leaving and kicking (`seat()`, `remove()`), heartbeats (`touch()`) and freeing idle seats (`releaseIdleSeats()`) | `tests/Feature/Table*` |
| `BoardSelectionService` | which board a table plays: deals when the fourth seat fills (`startPlayingIfFull()`), moves on after a finished board (`moveOn()`), detaches a board abandoned mid-play (`abandonPlaying()`) | feature tests |
| `PlayingStateService` | the one place that works out a playing's phase, calls, cards, turn, who acts, the hands and dummy | feature tests |
| `AuctionService` | one call (`call()`); `nextToCall`, `illegalReason`, `isOver`, `result` | `tests/Unit/AuctionServiceTest` |
| `CardPlayService` | one card (`play()`); `nextToPlay`, `actingSeat`, `illegalReason`, `trickWinner`, `tricks`, `tricksWon` | `tests/Unit/CardPlayServiceTest` |
| `ClaimService` | claims and concessions (`claim`, `respond`, `withdraw`) | `tests/Unit/ClaimServiceTest` |
| `ScoringService` | duplicate scoring (`score()`, from declarer's side) and matchpoints (`matchpoints()`), pure static functions | `tests/Unit/ScoringTest` |
| `BoardResultsService` | reads finished playings back for results across tables and a player's history | feature tests |

Things worth knowing before you change them:

- **Seating.** Taking a seat while already holding one *moves* the player:
  `unique(user_id)` on `table_seats` is never relaxed, so the old seat goes
  out through `remove()` first (or, at the same table, the row is updated in
  place so the table isn't deleted under its only player). Both tables are
  locked lowest id first so two opposite moves can't deadlock. A unique-index
  violation (SQLSTATE 23000) becomes `SeatUnavailableException`. `seat()`
  and `remove()` take an optional `$by`, the acting user, for when a manager
  seats or kicks somebody else.
- **Leaving** is the same code whether the player quit, was kicked or timed
  out: `remove()` deletes the table if that was the last player, otherwise
  hands `moderated_by` on (to the creator if still seated, else the
  earliest-joined player), and detaches an unfinished playing.
- **Boards** are chosen when the fourth player sits down, not when the table
  is created, because the selection rule
  ([`GAME-RULES.md` §8](GAME-RULES.md#8-game-flow-checklist-for-implementers))
  needs all four players' history. A playing abandoned mid-board is
  *detached* (`table_id` set to null), never deleted, so the seat snapshot
  still records that those four saw the deal.
- **Ending a board.** `BoardTable::finish()` is the only place a playing
  ends. It stores `score` **from N-S's side** (negated when E-W declared),
  while `ScoringService::score()` returns declarer's side.
- **Matchpoints are never stored**: every new playing of a board changes
  everyone's, so they are computed on each read.

## Authorization

Policies in `app/Policies/` are auto-discovered.

- `TablePolicy::manage` is true for a table's `moderated_by`, any `is_admin`
  user, or its `created_by` while that creator still holds a seat there.
  Every HTTP table payload carries it as `can_manage`, so clients don't
  re-implement it.
- `TablePolicy::play` limits the game state to players seated at the table.
- `BoardPolicy::view` lets a player see a board's deal, results and reviews
  only once they have **finished** that board, with no admin override, since
  anyone else may still be dealt it.

## Events and channels

Live updates go over websockets through Laravel Reverb (why Reverb, and how
to run it: [`RUNNING.md`](RUNNING.md#realtime-reverb)).

| Event | Channel | Carries | Sent when |
|---|---|---|---|
| `TableUpdated` | `table.{id}` | the `TableResource` JSON, without `can_manage` | any seat change that didn't delete the table |
| `PlayingUpdated` | `table.{id}` | the public game state (no hand, no `my_seat`) | a board is dealt, and after every accepted call, card or claim action |
| `HandDealt` | `App.Models.User.{id}` | that player's 13 cards | a board is dealt |

- Events implement `ShouldBroadcast` (queued, so a Reverb outage fails a
  queued job, not the player's request) and `ShouldDispatchAfterCommit`
  (dispatched inside the transaction, sent only if it commits). The payload
  is snapshotted in the constructor as the same JSON the HTTP endpoints
  return. `TableUpdated` is the one to copy for a new event.
- Channel auth (`routes/channels.php`) is checked only when a client
  subscribes, so a player who leaves the table stays subscribed. The table
  channel must therefore only carry what any player may see; anything private
  to one player goes on their `App.Models.User.{id}` channel. Dummy's hand
  and a claimer's hand are the exceptions, because they are face up.

## Scheduler, queue and commands

Three long-running processes sit next to `php artisan serve`:

- `php artisan queue:work --sleep=0.1` sends the queued broadcasts to Reverb.
- `php artisan reverb:start` holds the players' websocket connections.
- `php artisan schedule:work` runs what `routes/console.php` schedules: the
  `tables:release-idle-seats` command (`App\Console\Commands\ReleaseIdleSeats`)
  every minute.

Reverb can't tell Laravel that a client disconnected, so the backend tracks
presence with a heartbeat instead: `table_seats.last_seen_at` is set by
`POST /tables/{table}/heartbeat` and by the `seen` middleware
(`App\Http\Middleware\TouchTableSeat`) on every playing endpoint — add `seen`
to any new one. The command frees seats idle for
`config('bridge.idle_seat_minutes')` (5), or
`bridge.idle_playing_seat_minutes` (15) at a table mid-board, through the
normal `remove()`, so it behaves exactly like the player leaving.

All three keep the old code loaded: restart them after changing PHP.

## Seeding

`DatabaseSeeder` branches on `APP_ENV`. Production gets only cards, bids and
100 dealt boards. Everywhere else also gets an admin user
(`email@email.com` / `pass`) and one table per phase of the game
([`RUNNING.md`](RUNNING.md#seeded-data)).

The seeders play those tables through the real services —
`TableSeatService::seat()` (so the fourth player deals the board),
`AuctionService` (via `AuctionSeeder`), `CardPlayService` (via
`CardplaySeeder`) and `ClaimService` — so a seeded database only ever
contains legal play. `TableSeeder` wraps it all in `Event::fakeFor()`, so
seeding queues no broadcasts, and
`tests/Feature/Database/DatabaseSeederTest` replays every seeded call and
card through the rules. Seeders live in `database/seeders/` and
`database/seeders/game/` (namespace `Database\Seeders\game`).

The `AuctionFactory` and `CardplayFactory` make random, illegal rows on
boards with no hands: they are test filler, and the seeders don't use them.

## Tests

`php artisan test` runs PHPUnit on in-memory sqlite, so it never touches the
MySQL database and needs neither MySQL nor a `.env`
([`RUNNING.md`](RUNNING.md#tests)). Feature tests must extend
`Tests\TestCase`, which refuses to run anything unless the config really is
`APP_ENV=testing` on in-memory sqlite. Broadcasting is off in tests
(`BROADCAST_CONNECTION=null`): assert events with `Event::fake()`, and to
test a channel callback switch to the `reverb` driver inside the test (see
`TableBroadcastTest::useReverbBroadcaster`).

## Gotchas

- **Migrations are edited in place**, since nothing has shipped. After
  pulling a schema change run `php artisan migrate:fresh --seed`; a plain
  `migrate` sees nothing new. Keep migrations sqlite-compatible, or the
  tests break.
- **Delete tables through Eloquent** (`$table->delete()`), never a
  query-builder delete. A `Table::deleting` hook discards the call and card
  logs of any unfinished playing still attached; a query-builder delete
  skips it. A finished playing's logs outlive its table on purpose, for
  `GET /playings/{playing}`.
- **Tables have no closed state.** A table is active while somebody sits at
  it, and the last player to leave deletes it. `board_table.table_id` is
  therefore nullable (`nullOnDelete`), so board history survives.
- **2-space indentation**, including PHP (`.editorconfig`). Pint can't indent
  with 2 spaces, so `pint.json` turns its indentation fixers off: Pint
  neither catches nor fixes bad indentation. Don't remove those rules, or a
  plain `vendor/bin/pint` reindents the whole codebase to 4 spaces. Check
  formatting with `vendor/bin/pint --test`.
- **Debugbar** is force-enabled only when `APP_ENV=local`: its injected HTML
  would break `assertNoContent()` in tests.
- **`composer dev` doesn't work** (it runs `npm run dev` and there is no
  `package.json`); use `php artisan serve`.

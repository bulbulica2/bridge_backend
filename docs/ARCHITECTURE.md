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
  → middleware (auth, not-banned, seen, throttle)
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
  the `seen` and `not-banned` middleware aliases. Every game action sits in
  one `not-banned` route group (`App\Http\Middleware\EnsureNotBanned`): a
  banned user gets a 403 naming the end of the ban and its reason before
  anything else runs. Put any new route that changes something at a table
  inside it.
- **Controllers** in `app/Http/Controllers/Game/` extend `BaseController`.
  Answer with its `sendResponse($data, $message, $code)` and
  `sendError($message, $code, $errors = [])`, which both return
  `{status, message, data}`. Controllers stay thin: they call a service and
  map its exceptions to HTTP codes.
- **Form requests** hold validation, and authorization where it depends on
  the table: checking the policy in `authorize()` gives a non-manager a 403
  before validation runs (`AddUserToSeatRequest`, `AddRobotToSeatRequest`,
  `RemoveUserFromSeatRequest`).
- **Services** in `app/Services/` own every rule and every write. Reuse them
  instead of re-checking a rule in a controller (see [below](#game-services)).
- **Resources** fix the JSON shape so each thing looks the same wherever it
  appears: `TableResource` (every table payload, including the broadcast
  one), `TableSeatResource`, `PlayingResource` (the game state) and
  `UserResource`. `TableResource::withPlaying()` adds the caller's game
  state (`PlayingStateService::dealtStateFor()`, hand included) as
  `playing`; only `POST /tables`, `POST /tables/{table}/start`,
  `POST /tables/{table}/seats` and `POST /tables/{table}/seats/robots` call
  it, so a request that deals the board needs no
  `GET /tables/{table}/playing` after it. Anything else leaves the key out — above all `TableUpdated`,
  since the table channel must never carry a hand. Anything that shows a user to *other* players goes
  through `UserResource` (`id`, `name`, `username`, `description`,
  `is_robot`, `is_admin` — public so a client can hide **Remove** on an
  admin's seat), never
  the raw `User` model: `email` isn't in `$hidden`, because the owner needs
  it on `GET /api/user`. A seat's `user` and the game state's `players` use
  `PlayerResource`, the same less `description`, since those payloads are
  broadcast four players at a time (see [Message size](#message-size)).

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
| `TableSeatService` | joining, moving, leaving and kicking (`seat()`, `leave()`, `remove()`), heartbeats (`touch()`), freeing idle seats (`releaseIdleSeats()`), the away rule and set forfeit (`checkAway()`, `costsTheSet()`) and deleting unattended tables (`deleteUnattendedTables()`) | `tests/Feature/Table*` |
| `BoardSelectionService` | which board a table plays and when: Start (`start()`, `withdrawStart()`), deals once a full table's humans have all pressed it (`startIfReady()`) as the first board of a new set, moves on after a finished board within the set (by itself after `bridge.next_board_seconds`, `dealNext()` from the queued `App\Jobs\DealNextBoard`, or at once once every human asked, `moveOn()`), detaches a board abandoned mid-play (`abandonPlaying()`) and ends a set one of its players left (`abandonSet()`) or a side lost by going away (`forfeitSet()`) | `tests/Feature/Table/StartBoardTest`, `AssignBoardTest`, `SetForfeitTest`, `tests/Feature/Game/NextBoardTest`, `AutoNextBoardTest`, `BoardSetTest` |
| `PlayingStateService` | the one place that works out a playing's phase, calls, cards, turn, who acts (`actingUserId()`, with `dummyPlaysForDeclarer()`: a human dummy plays a robot declarer's cards), the hands, dummy and a human dummy's `declarer_hand` | feature tests (`HumanDummyPlaysTest` for the human dummy) |
| `AuctionService` | one call (`call()`, with its self-alert), a question about a call (`ask()`) and its bidder's answer (`explain()`), both also written into the chat; `nextToCall`, `illegalReason`, `isOver`, `result` | `tests/Unit/AuctionServiceTest`, `tests/Feature/Game/BidAlertTest` |
| `BoardChatService` | the board's chat: who may read a message (`messagesFor()`, `BoardMessage::visibleTo()`: never partner's `opponents` message until the board is finished), sending one (`send()`: `table` or `opponents` in every phase, a robot answering a question about its call with `robotReading()` or, as a defender, about its card with `RobotCarding::explain()`), and `post()`, which writes a message and pushes it to its human readers — `AuctionService` writes its questions and answers through it | `tests/Feature/Game/BoardChatTest` |
| `CardPlayService` | one card (`play()`); `nextToPlay`, `actingSeat`, `illegalReason`, `trickWinner`, `tricks`, `tricksWon` | `tests/Unit/CardPlayServiceTest` |
| `ClaimService` | claims and concessions (`claim`, `respond`, `withdraw`, and `expire`, which the queued `App\Jobs\ExpireClaim` runs `bridge.claim_seconds` after a claim: silence rejects it); a claim ended without an accept locks claims until the next card (`claim_locked`) | `tests/Unit/ClaimServiceTest`, `tests/Feature/Game/ClaimTest` |
| `ScoringService` | duplicate scoring (`score()`, from declarer's side) and matchpoints (`matchpoints()`), pure static functions | `tests/Unit/ScoringTest` |
| `BoardResultsService` | reads finished playings back for results across tables, a set's results (`set()`, `maySeeSet()`) and a player's history | feature tests (`BoardResultsTest`, `BoardSetTest`) |
| `DoubleDummyService` | double dummy analysis: queues a board's table when it is dealt (`queueTable()`, from `deal()`) and a contract's opening leads when a playing finishes (`queueLeads()`, from `BoardTable::finish()`), solves and stores each once (`solveTable()`, `solveLeads()`, run by `App\Jobs\SolveDoubleDummyTable` / `SolveOpeningLeads`), and reads them back (`forBoard()`, `forPlaying()`: `ready`, `pending` — queueing what is missing — or `unavailable`) | `tests/Feature/Game/DoubleDummyTest` (fake solver), `tests/Unit/DdsSolverTest` (real DDS) |
| `RobotService` | robot players: the pool they are seated from (`seatRobot()`), and one robot move at a time (`act()`: a call, a card or a claim, the robots' claim answers, ready) through the services above | `tests/Feature/Game/RobotPlayTest`, `tests/Feature/Table/RobotSeatingTest` |
| `UserBanService` | an admin's bans (`ban()`, `lift()`): a ban frees the user's seat through `TableSeatService::remove()` as a walk-out (mid-set their side forfeits at once), deletes their `sessions` rows, replaces their remember token and sends `UserBanned`; a new ban replaces the one in force | `tests/Feature/User/UserBanTest` |

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
  hands `moderated_by` on (to the **human** seated there longest, never
  the creator by preference), and detaches an unfinished playing. With only
  robots left it keeps the table *unattended* (`unattended_since`,
  `moderated_by` null); the first human to `seat()` there takes it over.
  Both reread the table under its row lock (`refresh()`), since who runs it
  may have changed since the caller loaded it.
- **Boards** are dealt once the table is full **and** every human there has
  pressed Start (`table_seats.ready_at`; robots are ready as they sit
  down) — never by filling the table alone, so nobody is thrown into an
  auction before reaching the table. Not when the table is created either,
  because the selection rule
  ([`GAME-RULES.md` §8](GAME-RULES.md#8-game-flow-checklist-for-implementers))
  needs all four players' history. `startIfReady()` runs on every Start and
  every `seat()` (a robot can be the one that completes the table); dealing
  clears the humans' Start. While the same four sit there, a finished board
  of a set is followed by its next board `bridge.next_board_seconds` later
  (`BoardTable::finish()` queues `App\Jobs\DealNextBoard`, which runs
  `dealNext()`; `PlayingStateService::nextBoardAt()` is the state's
  `next_board_at`), or at once by `moveOn()` once every human has asked
  (each player asks for themselves; nobody can ask for the others; robots
  count as asked). Both take the table row lock, so the board is dealt
  once. Once one of the four was replaced it takes everyone's Start.
- **Sets.** Boards come in sets of `bridge.set_size` (4): a Start's deal
  opens a `TableSet` (`openSet()`, which snapshots the four into
  `table_set_seats`), the timer or the humans' Next deals the set's next board
  (`board_table.table_set_id`/`set_position`), and `BoardTable::finish()`
  completes the set on its last board — after which `moveOn()` 409s and
  `start()` accepts a Start even with the same four seated. `remove()` ends
  an unfinished set as `abandoned` (`abandonSet()`), even between boards —
  or as lost by the leaver's side (`forfeitSet()`, with a `forfeit_reason`)
  when they ran out of time on their turn, were away or are moving to
  another table.
- **Away mid-set.** In the middle of a set a seat is never just freed by
  its player going: `TableSeatService::leave()` holds it on a Leave
  (`table_seats.away_since`), `checkAway()` marks quiet players away after
  `bridge.away_seconds` (60); `touch()` (any sign of life) brings them
  back. Away or not, the player on turn has a **turn clock**:
  `board_table.turn_started_at`, set by the deal, every call and card and a
  cleared claim (never by a heartbeat), plus `bridge.turn_seconds` (60) —
  `PlayingStateService::turnDeadline()`, the state's `turn_deadline`, null
  for a robot or an admin, between boards and while a claim is pending.
  Being stored on the playing and moved only by moves, it rides on the
  `PlayingUpdated` each move sends anyway. `checkAway()` takes the player
  whose clock ran out through `remove(..., forfeit: turn_timeout|away)`,
  which forfeits the set for their side. `forfeitReason()` and
  `costsTheSet()` hold the exceptions: robots are never away, an admin never
  costs their side the set, and while an admin is away a Leave or move is
  immediate and abandons (running out of time still forfeits).
  Sets outlive their table like playings do; `TableResource` and
  `PlayingResource` show where the table is as `set`.
  Start, like Next, takes the table row lock that seat changes take. A playing abandoned mid-board is
  *detached* (`table_id` set to null), never deleted, so the seat snapshot
  still records that those four saw the deal.
- **Ending a board.** `BoardTable::finish()` is the only place a playing
  ends. It stores `score` **from N-S's side** (negated when E-W declared),
  while `ScoringService::score()` returns declarer's side.
- **Matchpoints are never stored**: every new playing of a board changes
  everyone's, so they are computed on each read.
- **Double dummy** is the opposite: it depends only on the deal (the table)
  or on the deal, declarer and strain (the opening leads), so each is solved
  once and stored (`board_double_dummy`, `board_lead_analyses`). The solver
  is the `App\Solvers\DoubleDummySolver` interface; `App\Solvers\DdsSolver`
  calls DDS's C API through FFI (no CLI to build, the library loaded once
  per worker, and capped then with DDS's `SetResources` at
  `bridge.dds_memory_mb`/`dds_threads`, 256 MB and 2 threads: DDS would
  otherwise size itself for every core), and `AppServiceProvider` binds it
  only when `bridge.dds_library` (`DDS_LIBRARY`) is set — `DoubleDummyService::available()`
  is whether anything is bound. It runs **only in the queue**, never in a
  request: a full table is 20 solves. The jobs are `ShouldBeUnique`, so
  repeated reads while `pending` queue one each. Tests bind
  `Tests\Support\FakeDoubleDummySolver`; `phpunit.xml` forces `DDS_LIBRARY`
  empty. Show it only after `BoardPolicy::view`, like the deal.

## Robots

Robot players are `users` rows with `is_robot` (see
[`DATA-MODEL.md`](DATA-MODEL.md#user-users)); what they bid and play is in
[`ROBOTS.md`](ROBOTS.md). The code splits in two:

- **The brains**, `app/Robots/`: pure classes with no database, like the
  services' static rules, unit-tested in `tests/Unit/Robots/`.
  `RobotHand` (points, lengths, shape of a hand), `RobotBidder` (a call),
  `RobotCardPlayer` (a card), `RobotClaims` (when to claim, and accept or
  reject a claim). The card play is split by role over one `PlayView` of
  what the seat knows (hands it sees, cards out, voids shown, tricks
  needed): `DeclarerPlan` (the count of winners and losers, and the line),
  `DeclarerPlay`, `DefenderPlay` (with `LeadSafety`, what a defender on
  lead sees in dummy), `Signals`, `Discards`, and `Endgame`,
  which checks the last four tricks by solving every layout of the unseen
  cards with `DoubleDummy` — a plain exhaustive search, also what
  `RobotClaims` uses to answer a claim in a small ending, where three
  hands are face up and the fourth follows. The bidding system itself is one ordered list of rules
  per auction position, `BiddingSystem` (each a `BidRule`: a call, its
  `BidMeaning`, the hands that make it), over an `AuctionView` of the calls
  so far. A robot makes the first legal rule its hand fits, and every call
  — its partner's, a human's — is read back through the same rules, so
  what a robot means and what its partner understands can't drift apart;
  `BidMeaning::explanation()` is the text a robot alerts its conventional
  calls with (`BidMeaning::$alert`) and answers questions with. Each reads only the arrays
  `PlayingStateService::stateFor()` serves the robot's seat — its own hand,
  dummy once face up, a claimer's hand, the cards played — never another
  hand, so a robot knows no more than a human in its seat. Each returns a
  legal choice; the unit tests replay hundreds of random deals through
  `AuctionService`'s and `CardPlayService`'s rules to check it.
- **The driver**, `RobotService::act()`: works out whether a robot is due —
  the acting user in the auction or play (declarer's robot plays dummy;
  a robot declarer whose dummy is a human never acts in the play, nor
  answers a claim for declarer's seat),
  every robot yet to answer a pending claim (all in one move, stopping
  once a reject or the finishing accept ends the claim), or the first robot not yet
  ready for the next board — asks the brain, and makes the move through
  `AuctionService`, `CardPlayService`, `ClaimService` or
  `BoardSelectionService::moveOn()`, so it is checked like a human's. In the
  play, a robot on lead with nothing but top winners claims the rest
  instead of playing a card — never while `board_table.claim_locked` (a
  claim was refused and no card played since), which is what stops it
  claiming again after a rejection, which leaves the position unchanged. A
  choice the rules would refuse falls back to Pass or the first legal card;
  a move refused because the table changed meanwhile is dropped (that change
  sent its own event). It does nothing unless a human sits at the table and
  it isn't unattended.

`App\Listeners\DriveRobots` (auto-discovered, queued, `withDelay` =
`bridge.robot_delay_seconds`, cut to a second before `claim.expires_at`
while a claim is pending, so a robot's answer comes in time) calls `act()`
after every `PlayingUpdated`. A robot never answers a claim whose time is
up (`BoardTable::claimExpired()`).
Each robot move sends `PlayingUpdated` itself, so a run of robot turns is a
chain of one-move jobs, spaced out for the human watching. On the `sync`
queue (tests) that chain would nest a job inside a job 52 deep for a board
the robots play out — past xdebug's nesting limit — so the listener
trampolines: a job started while another is running only queues its table,
and the outer one works through them in turn.

The pool: `seatRobot()` seats the first robot that sits nowhere, or makes a
new `robot-<n>`, through `TableSeatService::seat()` with the asking human
as `$by` — so a robot seated elsewhere at the same moment is a 409 (then
another robot is tried), never a move. Board selection ignores robots'
history, and `HandDealt` isn't sent to them.

## Authorization

Policies in `app/Policies/` are auto-discovered.

- `TablePolicy::manage` is true for a table's `moderated_by` and any
  `is_admin` user; `created_by` grants nothing.
  Every HTTP table payload carries it as `can_manage`, so clients don't
  re-implement it.
- `TablePolicy::kick` (a `Response`, returned from
  `RemoveUserFromSeatRequest::authorize()` through `Gate::inspect()` so the
  403 names the reason) lets anyone take their own seat, a manager anyone
  else's, and anyone a robot's at an unattended table — except an admin's,
  which only the admin or another admin may take.
- `TablePolicy::play` limits the game state to players seated at the table.
- `UserPolicy::ban` lets only an admin ban, and never themselves, another
  admin or a robot (a `Response` with the reason, returned from
  `BanUserRequest::authorize()`); `UserPolicy::manageBans` (admins) gates
  lifting a ban and seeing a user's bans on `GET /users/{user}`.
- `BoardPolicy::view` lets a player see a board's deal, results and reviews
  only once they have **finished** that board, with no admin override, since
  anyone else may still be dealt it.
- `TableSetPolicy::view` lets a set's four players see its results
  (`GET /sets/{set}`), and anyone else only once they have finished every
  board it finished — the same reasoning, no admin override.

## Events and channels

Live updates go over websockets through Laravel Reverb (why Reverb, and how
to run it: [`RUNNING.md`](RUNNING.md#realtime-reverb)).

| Event | Channel | Carries | Sent when |
|---|---|---|---|
| `TableUpdated` | `table.{id}` | the `TableResource` JSON, without `can_manage` | any seat change that didn't delete the table, a Start pressed or taken back, a board dealt, a player away or back, a forfeit clock started or stopped |
| `PlayingUpdated` | `table.{id}` | the public game state (no hand, no `my_seat`) in its compact shape (`PlayingResource::compact()`: cards and bids as ids) | a board is dealt, and after every accepted call, card or claim action (a robot's too); `DriveRobots` listens to it |
| `HandDealt` | `App.Models.User.{id}` | that player's 13 cards | a board is dealt (humans only) |
| `DeclarerHandShown` | `App.Models.User.{id}` | declarer's 13 cards (`declarer_hand`) | an auction ends with a robot declarer and a human dummy, who plays both hands (to that human only; `AuctionService`) |
| `CallAlerted` | `App.Models.User.{id}` | `index` of the call in the auction, its `explanation` | a call is alerted or its bidder explains it (a robot's too): to each human opponent of the bidder, never partner (`AuctionService::alertTo()`) |
| `CallQuestioned` | `App.Models.User.{id}` | `index` of the call, `asked_by` | an opponent asks about a human's call: to its bidder only (`AuctionService::ask()`) |
| `BoardMessageSent` | `App.Models.User.{id}` | `table_id`, `playing_id` and the chat `message` (`BoardMessageResource`) | a chat message is written (sent, a robot's answer, or an alert question or answer): to each human seated at the table who may read it, the sender included — all four for a `table` message, never partner for an `opponents` one during the board, never the table channel (`BoardChatService::post()`) |
| `UserBanned` | `App.Models.User.{id}` | the ban: `reason`, `until`, `banned_at` | an admin bans that user, so their open client logs out |

- Events implement `ShouldBroadcast` (queued, so a Reverb outage fails a
  queued job, not the player's request) and `ShouldDispatchAfterCommit`
  (dispatched inside the transaction, sent only if it commits). The payload
  is snapshotted in the constructor as the same JSON the HTTP endpoints
  return (`PlayingUpdated`: compacted). `TableUpdated` is the one to copy
  for a new event — and add it to `BroadcastSizeTest`.
- Channel auth (`routes/channels.php`) is checked only when a client
  subscribes, so a player who leaves the table stays subscribed. The table
  channel must therefore only carry what any player may see; anything private
  to one player goes on their `App.Models.User.{id}` channel. Dummy's hand
  and a claimer's hand are the exceptions, because they are face up. Alerts
  are private too, to the bidder's opponents: they go on the opponents' own
  channels, and only `stateFor()` (per viewer) shows them — never
  `PlayingResource`'s public part. The table channel refuses a banned
  user.

### Message size

Hosted Pusher refuses an event over 10 KB, and Reverb an HTTP request over
its `max_request_size`; either way the queued broadcast job fails
(`Pusher error: Payload too large.`) and the table never sees that state.
`App\Broadcasting\PusherBody::of()` builds the body exactly as the Pusher
SDK does (the data JSON-encoded as a string inside the JSON, so escaped
twice), and every event must stay within `PusherBody::BUDGET` (9,000 bytes,
the rest of the 10 KB being the HTTP request around it).
`tests/Feature/Game/BroadcastSizeTest` builds each event's largest payload:
the longest legal auction (319 calls), 13 tricks and the deal, a pending
13-card claim, every name at its limit in emoji (14 bytes a character).
What keeps them there:

- `PlayingUpdated` broadcasts `PlayingResource::compact()` of the public
  state: cards and bids as ids, the auction as a list of bid ids, tricks as
  `{leader, cards, winner}` — a client expands it with `GET /cards` and
  `GET /bids` (the format is in [`API.md`](API.md#event-playingupdated)).
- `PlayerResource` leaves `description` (up to 1000 characters) out of
  seats and `players`.
- The free text a broadcast carries is capped: `User::NAME_MAX` (50),
  `User::USERNAME_MAX` (30), `Table::NAME_MAX` (50),
  `UserBan::REASON_MAX` (500). Raise one only with the test still passing.

A broadcast that fails anyway is logged at `error` by
`App\Listeners\LogFailedBroadcast` (on `JobFailed`, not queued): event,
channels, table or user id, size and the error. Locally Reverb's
`max_request_size` is raised to 64 KB (`REVERB_MAX_REQUEST_SIZE`), so a
payload that outgrows the budget still gets through while it's fixed.

## Scheduler, queue and commands

Three long-running processes sit next to `php artisan serve`:

- `php artisan queue:work --sleep=0.1` sends the queued broadcasts to Reverb,
  runs the robots' moves (`DriveRobots`), expires unanswered claims
  (`App\Jobs\ExpireClaim`, dispatched `afterCommit()` with a delay up to
  the claim's `claim_expires_at` — a delayed job, not a scheduled check,
  since the 10-second schedule tick is as long as the whole deadline) and
  deals a set's next board when its pause is up (`App\Jobs\DealNextBoard`,
  dispatched the same way from `BoardTable::finish()` with a delay up to
  `next_board_at`). It also solves the double dummy analysis
  (`App\Jobs\SolveDoubleDummyTable` when a board is dealt,
  `App\Jobs\SolveOpeningLeads` when a playing finishes; see
  [Game services](#game-services)), the only place DDS ever runs.
  It reports on itself through three listeners, none queued:
  `App\Listeners\LogWorkerStopping` (`WorkerStopping`) logs every stop at
  `warning` with its exit code (12: `--memory` reached) and PHP memory;
  `LogJobMemory` (`JobProcessed`) logs each job's memory at `debug`, only
  with `bridge.log_job_memory`; `BeatQueueHeartbeat` (`Looping`, before
  every poll) writes a heartbeat to the cache through
  `App\Services\QueueHealthService` (a singleton, so it writes at most
  every 10 s and never throws), which `GET /api/health`
  (`HealthController`) reads back. How to keep it running:
  [`RUNNING.md`](RUNNING.md#keeping-the-worker-running).
- `php artisan reverb:start` holds the players' websocket connections.
- `php artisan schedule:work` runs what `routes/console.php` schedules, every
  minute: `tables:release-idle-seats`
  (`App\Console\Commands\ReleaseIdleSeats`) and `tables:delete-unattended`
  (`App\Console\Commands\DeleteUnattendedTables`, which deletes tables only
  robots have kept for `bridge.unattended_table_minutes` (10)); and every
  **ten seconds** `tables:check-away` (`App\Console\Commands\CheckAwayPlayers`,
  `TableSeatService::checkAway()`), since a minute is too coarse for the
  one-minute turn clock. It is a frequent check rather than a delayed
  job per turn or per away player: being away starts with a heartbeat that
  *doesn't* come, which no event marks, and one check serves both rules
  without queueing a job for every call and card.

Reverb can't tell Laravel that a client disconnected, so the backend tracks
presence with a heartbeat instead: `table_seats.last_seen_at` is set by
`POST /tables/{table}/heartbeat` and by the `seen` middleware
(`App\Http\Middleware\TouchTableSeat`) on every playing endpoint — add `seen`
to any new one. `tables:release-idle-seats` frees seats idle for
`config('bridge.idle_seat_minutes')` (5) at a table that isn't mid-set,
through the normal `remove()`, so it behaves exactly like the player
leaving; mid-set `tables:check-away` marks them away instead, and forfeits
the set for the side of whoever lets their turn clock run out. Robots send
no heartbeat, so both skip them; an admin's seat is never freed by either
(`tables:check-away` may show an admin away, but never forfeits or frees
for it).

All three keep the old code loaded: restart them after changing PHP.

## Seeding

`DatabaseSeeder` branches on `APP_ENV`. Production gets only cards, bids and
100 dealt boards. Everywhere else also gets an admin user
(`email@abc.com` / `pass`) and one table per phase of the game, plus one
whose set of boards is over
([`RUNNING.md`](RUNNING.md#seeded-data)).

The seeders play those tables through the real services —
`TableSeatService::seat()` and `BoardSelectionService::start()` (every
player of a full table presses Start, so the last one deals the board, and
`moveOn()` for the next board of a set, which leaves a `DealNextBoard`
job queued for each finished first board — run by the next `queue:work`),
`AuctionService` (via `AuctionSeeder`), `CardPlayService` (via
`CardplaySeeder`) and `ClaimService` — so a seeded database only ever
contains legal play. `TableSeeder` wraps it all in `Event::fakeFor()`, so
seeding queues no broadcasts (and no robot moves — no seeded table has
robots), and
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
`TableBroadcastTest::useReverbBroadcaster`). The queue is `sync`, so robots
move inside the request that made them due; faking `PlayingUpdated` holds
them back while a test sets a table up (`RobotPlayTest::claimTable`).

CI (`.github/workflows/tests.yml`) runs three checks on every PR and push to
`main`: `pint` (`pint --test`), `tests` (the suite, no coverage driver) and
`coverage` (the suite again with pcov). `coverage` hands the Clover report to
`scripts/coverage.php`, which writes line and method coverage of `app/` per
directory, and the files with lines no test runs, to the job summary, and
**fails under 95% of lines** — the whole suite's number, not the unit
tests'. The floor only goes up: a change that would drop under it comes with
the tests that keep it there. The HTML report, browsable per file, is the
run's `coverage-html` artifact; `composer coverage` makes the same report
locally ([`RUNNING.md`](RUNNING.md#coverage)).

Services that lock rows and run transactions are tested through HTTP
feature tests on sqlite, so `tests/Unit` alone covers only about half of
`app/`: the pure rules (auction, card play, claims, scoring) and the robots.
A race the sweeps or `seat()` recheck under a lock can't happen on one
connection, so tests stage it from a model event or query listener fired at
the right moment (`TableSeatServiceTest`, `SweepRaceTest`). `RobotService`'s
`chooseCall()`/`chooseCard()` exist so a test can hand a robot a move the
rules refuse (`RobotFallbackTest`).

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
  therefore nullable (`nullOnDelete`), so board history survives. Robots
  alone keep a table alive, as *unattended*, until the scheduler deletes it.
- **Robots need the queue worker.** Without `queue:work` their jobs wait in
  `jobs` and the table stalls on a robot's turn. A robot job that throws
  fails like any job (`queue:retry`). Claims need it too: without it an
  unanswered claim never expires (though no answer is taken after its
  `expires_at`), and a set's next board waits for every human's Next.
- **Delayed jobs run at once in the tests.** The `sync` queue ignores
  `delay`, so `ExpireClaim` runs the moment its claim is made and
  `DealNextBoard` the moment its board finishes and, not due yet, they do
  nothing. Tests travel in time and run the job themselves
  (`ClaimTest::runJob()`, `AutoNextBoardTest::runJob()`).
- **2-space indentation**, including PHP (`.editorconfig`). Pint can't indent
  with 2 spaces, so `pint.json` turns its indentation fixers off: Pint
  neither catches nor fixes bad indentation. Don't remove those rules, or a
  plain `vendor/bin/pint` reindents the whole codebase to 4 spaces. Check
  formatting with `vendor/bin/pint --test`.
- **Debugbar** is force-enabled (`AppServiceProvider::register`) only when
  `APP_ENV=local` and not in the console (#127: in `queue:work` it kept
  every transaction, without limit, until the worker reached `--memory`
  and stopped silently every 10–25 minutes), since `enable()` skips debugbar's own testing check and its
  injected HTML would break `assertNoContent()` in tests. `enable()` also
  overrides `config('debugbar.enabled')`, so the provider skips it when
  `.env` says `DEBUGBAR_ENABLED=false`; without that check the key did
  nothing. Turning it off saves ~0.01–0.02 s on a light request and ~0.14 s
  on `POST /tables` with robots (see
  [`RUNNING.md`](RUNNING.md#local-speed)).
- **`composer dev` doesn't work** (it runs `npm run dev` and there is no
  `package.json`); use `php artisan serve`.

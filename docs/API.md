# API Reference

Base URL (local): `http://127.0.0.1:8000` (see [`RUNNING.md`](RUNNING.md))

Auth-related routes are documented separately in [`AUTH.md`](AUTH.md). This
file covers the game/domain endpoints (`routes/web.php`) and the one plain
API route. Entity fields referenced below are defined in
[`DATA-MODEL.md`](DATA-MODEL.md).

All game endpoints return JSON via `BaseController::sendResponse()`:

```json
{
  "status": 200,
  "message": "Cards retrieved successfully.",
  "data": [ ... ]
}
```

`BaseController::sendError(string $message, int $code, array $errors = [])`
returns the same shape for errors, with `data` holding the `$errors` array
(e.g. `{"status": 409, "message": "...", "data": []}`). The table, seat,
call and card endpoints use it for 409s. Laravel validation failures still use the framework's default
422 JSON (`{message, errors}`), not this shape. Unauthenticated JSON requests
to `auth` routes get Laravel's default `401 {"message": "Unauthenticated."}`,
and policy failures get Laravel's default `403 {"message": "..."}`.

A **banned** user (see [Bans](#bans)) gets a 403 in the envelope shape from
every game action — `POST /tables` and every route under `/tables/{table}/`
except `GET /tables/{table}` — with the end of the ban and its reason in the
message and the ban in `data`:

```json
{
  "status": 403,
  "message": "You are banned until 12 Oct 2026: Playing two accounts at once.",
  "data": {"ban": {"reason": "Playing two accounts at once.", "until": "2026-10-12T18:30:00.000000Z", "banned_at": "2026-10-05T18:30:00.000000Z"}}
}
```

It comes before any other check (seated, policy, validation). Reading — the
lobby, profiles, histories, results — stays open.

## Implemented & routed

| Method | Path | Controller@action | Auth | Returns |
|---|---|---|---|---|
| GET | `/` | closure | none | `{"Laravel": "<version>"}` — health check |
| GET | `/cards` | `Game\CardController@index` | none | all `Card` rows |
| GET | `/cards/{card}` | `Game\CardController@show` | none | one `Card` by id |
| GET | `/bids` | `Game\BidController@index` | none | the 38 calls with their ids (`bid_id` for `POST /tables/{table}/calls`), P, X, XX, then by rank |
| GET | `/tables` | `Game\TableController@index` | `auth` | all tables, newest first, with `seats.user`, `free_seats`, `set` and `can_manage` |
| POST | `/tables` | `Game\TableController@store` | `auth` | creates a table and seats the creator, with `robots` in the other three seats if asked (201) |
| GET | `/tables/{table}` | `Game\TableController@show` | `auth` | one table with `seats.user`, `free_seats`, `set` and `can_manage` |
| POST | `/tables/{table}/seats` | `Game\TableSeatController@store` | `auth` | take a free seat at an existing table, moving off your old one if you had one (201) |
| DELETE | `/tables/{table}/seats` | `Game\TableSeatController@destroy` | `auth` | give up your seat; deletes the table if you were the last player |
| POST | `/tables/{table}/seats/users` | `Game\TableSeatController@storeUser` | `auth` + `TablePolicy::manage` | a table manager seats another user (201) |
| POST | `/tables/{table}/seats/robots` | `Game\TableSeatController@storeRobot` | `auth` + `TablePolicy::manage` | a table manager puts a robot in a free seat (201) |
| DELETE | `/tables/{table}/seats/{user}` | `Game\TableSeatController@destroyUser` | `auth` (+ `TablePolicy::kick`: a manager, to remove anyone but yourself or an admin; another admin, to remove an admin; anyone, to remove a robot from an unattended table) | quit your seat, or kick that player out |
| POST | `/tables/{table}/start` | `Game\TableStartController@store` | `auth` + seated at the table (`TablePolicy::play`) | press Start; the first board of a new set is dealt once the table is full and every human there has pressed it (200, the table plus `playing`) |
| DELETE | `/tables/{table}/start` | `Game\TableStartController@destroy` | `auth` + seated at the table (`TablePolicy::play`) | take your Start back while no board is dealt (200, the table) |
| POST | `/tables/{table}/heartbeat` | `Game\TableSeatController@heartbeat` | `auth` + seated at the table (`TablePolicy::play`) | "still here": keeps the caller's seat from being freed as idle (200, `{last_seen_at}`) |
| GET | `/tables/{table}/playing` | `Game\PlayingController@show` | `auth` + seated at the table (`TablePolicy::play`) | the game state of the table's current board, with the caller's own hand |
| POST | `/tables/{table}/calls` | `Game\CallController@store` | `auth` + seated at the table (`TablePolicy::play`) | make your call in the auction, optionally alerted to the opponents (201, the updated game state) |
| POST | `/tables/{table}/calls/{index}/question` | `Game\CallController@question` | `auth` + seated at the table (`TablePolicy::play`) | ask the opponents what one of their calls means; a robot answers at once (200, the updated game state) |
| PUT | `/tables/{table}/calls/{index}/explanation` | `Game\CallController@explain` | `auth` + seated at the table (`TablePolicy::play`) | explain your own call: answer a question, fix your alert, or alert late (200, the updated game state) |
| GET | `/tables/{table}/messages` | `Game\BoardMessageController@index` | `auth` + seated at the table (`TablePolicy::play`) | the current board's [chat](#chat): the messages the caller may read |
| POST | `/tables/{table}/messages` | `Game\BoardMessageController@store` | `auth` + seated at the table (`TablePolicy::play`) + `throttle:board-messages` (10 per 30 s) | a chat message to the whole table or to the opponents, in any phase, optionally about a call or a card (201, the message) |
| POST | `/tables/{table}/playing/next` | `Game\PlayingController@next` | `auth` + seated at the table (`TablePolicy::play`) | optional "deal now": the set's next board comes by itself at `next_board_at`; once every human has asked, it is dealt at once (200, the game state; 409 after the set's last board) |
| POST | `/tables/{table}/cards` | `Game\CardPlayController@store` | `auth` + seated at the table (`TablePolicy::play`) | play the next card of the trick — yours, or dummy's as declarer (201, the updated game state) |
| POST | `/tables/{table}/claim` | `Game\ClaimController@store` | `auth` + seated at the table (`TablePolicy::play`) | claim some of the remaining tricks for your side, 0 to concede (201, the updated game state) |
| POST | `/tables/{table}/claim/response` | `Game\ClaimController@respond` | `auth` + seated at the table (`TablePolicy::play`) | accept or reject the pending claim (200, the updated game state) |
| DELETE | `/tables/{table}/claim` | `Game\ClaimController@destroy` | `auth` + seated at the table (`TablePolicy::play`) | withdraw your own pending claim (200, the updated game state) |
| GET | `/users?search=` | `UserController@index` | `auth` + `throttle:30,1` | find users by username or name (public profiles + `seated`, at most 10) |
| GET | `/users/{user}` | `UserController@show` | `auth` | another user's public profile (no email); an admin also gets their `ban` and `bans` |
| POST | `/users/{user}/ban` | `UserBanController@store` | `auth` + admin (`UserPolicy::ban`) | ban that user for some days: frees their seat (mid-set a robot takes it), logs them out (201) |
| DELETE | `/users/{user}/ban` | `UserBanController@destroy` | `auth` + admin | lift their ban at once (200; 404 if not banned) |
| GET | `/users/{user}/playings` | `UserController@playings` | `auth` | that user's finished playings, latest first, paginated |
| GET | `/users/{user}/stats` | `UserController@stats` | `auth` | that user's stats: boards, sets, win rates, sets walked out on |
| GET | `/boards/{board}` | `Game\BoardController@show` | `auth` + finished that board (`BoardPolicy::view`) | the board with all four hands as dealt |
| GET | `/boards/{board}/results` | `Game\BoardController@results` | `auth` + finished that board (`BoardPolicy::view`) | every finished playing of the board, with matchpoints |
| GET | `/boards/{board}/double-dummy` | `Game\BoardController@doubleDummy` | `auth` + finished that board (`BoardPolicy::view`) | the board's double dummy table (tricks for each declarer and strain), or `pending` until the queue has solved it |
| GET | `/playings/{playing}` | `Game\PlayingController@review` | `auth` + finished that playing's board (`BoardPolicy::view`) | one finished playing with its auction, tricks and double dummy analysis, to review it |
| GET | `/sets/{set}` | `Game\TableSetController@show` | `auth` + one of the set's players, or finished all its boards (`TableSetPolicy::view`) | a set of boards' results: each board's result and matchpoints, totals per side, the winner |
| GET | `/api/user` | closure | `auth:sanctum` | current authenticated `User` (the caller's own record, email, `is_admin` and `ban` included) |
| PATCH | `/api/user` | `UserController@update` | `auth:sanctum` | edit your own `name` / `description` |
| GET | `/api/user/playings` | `UserController@ownPlayings` | `auth:sanctum` | your own finished playings, as `GET /users/{user}/playings` |
| GET | `/api/user/stats` | `UserController@ownStats` | `auth:sanctum` | your own stats, as `GET /users/{user}/stats` |
| GET | `/api/health` | `HealthController@show` | none | whether a queue worker is running (`data.queue.running`, `last_seen_at`) — see [below](#get-apihealth) |
| GET, POST | `/broadcasting/auth` | Laravel's `BroadcastController@authenticate` | session (`web` group) | signs a websocket subscription to a private channel, or 403 — see [Realtime](#realtime-websocket) and [`AUTH.md`](AUTH.md#websocket-channels-reverb) |

Example:
```bash
curl http://127.0.0.1:8000/cards
curl http://127.0.0.1:8000/cards/1
curl http://127.0.0.1:8000/bids
```

### `GET /api/health`
Whether a `queue:work` is running, which a client can't tell by itself: a
stopped worker leaves the table on screen as it was, while robots, live
updates and the next deal all wait for it (an overdue claim still expires,
on the next request or `tables:check-away`). Every worker
writes a heartbeat to the cache at most every 10 s as it loops
(`App\Listeners\BeatQueueHeartbeat`, `App\Services\QueueHealthService`);
`running` is whether the last one is under 60 s old. No auth; always 200.

```json
{
  "status": 200,
  "message": "The queue worker is running.",
  "data": { "queue": { "running": true, "last_seen_at": "2026-10-05T16:04:43Z" } }
}
```
With no worker, `running` is `false` and the message is "The queue worker
is not running: robots, live updates, claim expiry and the next deal wait
for it."; `last_seen_at` is the last heartbeat, or `null` if the cache has
none. (Laravel's own `GET /up` only says the app boots.)

### `GET /bids`
The 38 calls a player can make, each with the id that
[`POST /tables/{table}/calls`](#post-tablestablecalls) takes as `bid_id`.
No auth: it is static reference data, like `/cards`. Built by
`Game\BidController@index`.

- **200**, message `"Bids retrieved successfully."`, `data` a list of
  `{id, call, level, strain, special}`, exactly the `bid` shape of the game
  state's `auction` (`PlayingResource::bid`).
- Order: `P`, `X`, `XX`, then the 35 contract bids by rank
  (`1C`, `1D`, `1H`, `1S`, `1NT`, `2C` … `7NT`: level, then strain
  ♣ < ♦ < ♥ < ♠ < NT). The order comes from `level`/`strain`, never from the
  ids, which aren't pinned ([`DATA-MODEL.md`](DATA-MODEL.md#bid-bids)): a
  client should load the list and look calls up by `call`, not hard-code ids.

```json
{
  "status": 200,
  "message": "Bids retrieved successfully.",
  "data": [
    {"id": 1, "call": "P", "level": null, "strain": null, "special": true},
    {"id": 2, "call": "X", "level": null, "strain": null, "special": true},
    {"id": 3, "call": "XX", "level": null, "strain": null, "special": true},
    {"id": 4, "call": "1C", "level": 1, "strain": "C", "special": false},
    ...
    {"id": 38, "call": "7NT", "level": 7, "strain": "NT", "special": false}
  ]
}
```

The table routes need a logged-in session (see [`AUTH.md`](AUTH.md)).

## Tables

A table exists only while somebody sits at it. There is no closed or archived
state and no `closed_at` field: the last player to leave **deletes** the table.
See [`DATA-MODEL.md`](DATA-MODEL.md#table-tables) for the lifecycle.

Every table payload — from index, store, show or leave — is built by
`App\Http\Resources\TableResource` and has the same shape: the `Table` fields
(`id`, `name`, `created_by`, `moderated_by`, `board_id`, `unattended_since`,
timestamps), plus
`seats` (`TableSeat` rows — `id`, `table_id`, `user_id`, `seat`,
`last_seen_at`, `ready_at`, `away_since`, timestamps — each
with its `user` and `ready`),
`free_seats` (the unoccupied seats in `N, E, S, W` order), `set` and
`can_manage`.
`set` is where the table is in its [set of boards](#sets): the set it is on
now, or the one it finished last — `{id, number, board, of, finished,
ended, replaced}` as in the game state, `board` being how many of its
boards have been dealt — and `null` before the table's first Start.
A seat's `away_since` is null unless its player is
[away mid-set](#away-mid-set-and-the-turn-clock): it is since when (their
last sign of life, or their Leave). A seat has **no clock of its own** any
more (the old `forfeit_at` is gone): how long the board still waits for the
player on turn, away or not, is the game state's
[`turn_deadline`](#get-tablestableplaying).
A seat's `ready` (boolean, from `ready_at`) is whether its player has pressed
**Start** (see [`POST /tables/{table}/start`](#post-tablestablestart)); a
robot's is always true. It is public: everyone at the table sees who is
waiting for whom. The `TableUpdated` websocket event carries this same
shape less `can_manage` (see [Realtime](#realtime-websocket)).

`can_manage` (boolean) is whether **the caller** may manage the table —
seat other users and kick players — i.e. `TablePolicy::manage` evaluated for
them (see [`AUTH.md`](AUTH.md#authorization)): true for its `moderated_by`
and for any admin, seated or not — never for `created_by` as such. Show
the manager controls from it rather than re-deriving the rule from
`moderated_by`. In `GET /tables` it is per table, for the
caller.

A seated player's `user` is their **public profile** less its
`description` (`App\Http\Resources\PlayerResource`, via
`TableSeatResource`): `id`, `name`, `username`, `is_robot`, `is_admin`. It
never carries `email` (or anything else from the `users` row), so listing
tables does not reveal who plays under which address. The whole profile,
`description` included, is `GET /users/{user}`: open it from there, since
the description (up to 1000 characters) would take four players' worth of a
broadcast's [10 KB](#message-size). `is_admin` marks a seat only another admin may take
away (see `DELETE /tables/{table}/seats/{user}`): hide **Remove** on it
unless the caller is an admin, and show an **Admin** badge — players can
see an admin is at the table.

**Robots.** A seat can hold a robot player instead of a human: a `users` row
with `is_robot: true`, username `robot-<n>`, from a pool that grows as it is
needed (`App\Services\RobotService`). Robots are seated by
`POST /tables` with `robots: true` or by a manager with
`POST /tables/{table}/seats/robots`; they bid, play, answer claims and ask
for the next board (they never hold it up: they count as asking) on their own, through the same rules as a human, about a
second (`BRIDGE_ROBOT_DELAY_SECONDS`) after each move — see
[`ROBOTS.md`](ROBOTS.md) for how they decide. They act **only while at least
one human sits at the table**. A robot never becomes `moderated_by`, and
nobody else ever takes a seat a human left: it stays free until somebody
(human, or a robot a manager adds) fills it.

**Unattended tables.** When the last human leaves a table that still has
robots, the table is kept but **unattended**: `unattended_since` is set,
`moderated_by` goes to null (so only admins manage it), its robots stop
acting, and **anyone** may kick a robot from it. The first human to sit down
clears `unattended_since` and becomes `moderated_by`. Otherwise the scheduled
`tables:delete-unattended` deletes it `BRIDGE_UNATTENDED_TABLE_MINUTES`
(default 10) after its last human left (no event is sent: no human is
there). `unattended_since` is null at every other table.

**Dealing.** `board_id` is null until the table has a board. Filling the
table does **not** deal one: a board is dealt once the table is **full** and
**every human** seated there has pressed Start
([`POST /tables/{table}/start`](#post-tablestablestart)). Robots are ready
from the moment they sit down, so a human alone with three robots deals with
their one Start. A Start pressed before the table is full is kept, and the
board is dealt as soon as the fourth seat is taken if every human there has
pressed it by then (only possible when a robot takes it: a human sitting down
is never ready). The request that deals is the first to carry a non-null
`board_id`. Dealing clears every human's Start. `board_id` goes back to null
if a player leaves before the board is finished, and the refilled table needs
everyone's Start again. Once a board is finished, the **same four** get the
set's next board by themselves `BRIDGE_NEXT_BOARD_SECONDS` (10) later, at the
state's `next_board_at` (or at once with
[`POST /tables/{table}/playing/next`](#post-tablestableplayingnext)); if one of
them was replaced meanwhile, it is Start again. Boards come in
[sets](#sets) of four: Start deals a set's first board, the other three
follow by themselves, and after the fourth it is everyone's Start again for
the next set.

Start belongs to the seat: leaving, moving (to another table or another seat
at this one), being kicked or being released as idle all drop it, and
whoever sits down starts not ready. Nobody presses Start for anyone else, not
a manager or an admin either: a player who never presses is handled like any
idle player, or removed by a manager.

### `GET /tables`
Every table. Ordered by `created_at` then `id`, newest first.

Tables always have at least one player: the API deletes a table when its
last player leaves, and the seeders create theirs the same way. That player
may be a robot, at an unattended table.

### `POST /tables`
Body (JSON, both optional):

| Field | Rules | Default |
|---|---|---|
| `name` | nullable string, max 50 characters (`Table::NAME_MAX`, see [Message size](#message-size)) | `null` |
| `seat` | one of `N`, `E`, `S`, `W` | `N` |
| `robots` | boolean | `false` |

Rules:
- **409** if the user already holds a seat at any table (`table_seats.user_id`
  is unique) — leave it first. Creating deliberately does **not** move you the
  way `POST /tables/{table}/seats` does: a new table also counts against the
  3-active-tables limit, so leaving is made an explicit step.
- **409** if the user already has **3 active tables** they created
  (`Table::MAX_ACTIVE_PER_CREATOR`). A table counts while at least one
  **human** sits at it (`Table::attended()`); an unattended table, kept only
  by robots, doesn't. `created_by` never changes hands, so tables a user
  created and then left to other humans still count against their limit
  until those humans leave too.
- Otherwise, in one DB transaction: create the table with `created_by` and
  `moderated_by` set to the user and `board_id` null, then seat the user
  through `App\Services\TableSeatService::seat()`. If the seat fails, including
  a concurrent request hitting a unique index, nothing is created and the
  response is 409.
- With `robots` true, in the same transaction a robot takes each of the
  other three seats, so the table is full (`free_seats` empty) and the robots
  are `ready`. Either way `board_id` stays null: nothing is dealt until the
  creator presses Start ([`POST /tables/{table}/start`](#post-tablestablestart)),
  which then deals at once. The robots start calling as soon as it does; the
  human sees the auction reach their turn through `PlayingUpdated` (or
  `GET /tables/{table}/playing`).
- `playing` is always `null` here, since a new table has no board. The key
  is kept so this response has the shape of the others that carry it:
  `POST /tables/{table}/start`, `POST /tables/{table}/seats` and
  `POST /tables/{table}/seats/robots`, where it is the caller's game state,
  exactly what [`GET /tables/{table}/playing`](#get-tablestableplaying)
  would answer next (phase, auction, `my_seat`, their 13-card `hand`...),
  when the table has a playing after the request. `GET /tables`,
  `GET /tables/{table}`, the other seat endpoints and `TableUpdated` don't
  have the key at all, since it holds a hand.
- **422** (default Laravel shape) for an invalid `name`/`seat`/`robots`.

201 response (`data` has the same shape as `GET /tables/{table}`):
```json
{
  "status": 201,
  "message": "Table created successfully.",
  "data": {
    "id": 7, "name": "Friday club", "created_by": 3, "moderated_by": 3,
    "board_id": null, "created_at": "...", "updated_at": "...",
    "unattended_since": null,
    "seats": [{"id": 12, "table_id": 7, "user_id": 3, "seat": "E", "last_seen_at": "...", "ready_at": null,
               "away_since": null, "created_at": "...", "updated_at": "...", "ready": false,
               "user": {"id": 3, "name": "Ann", "username": "ann", "is_robot": false, "is_admin": false}}],
    "free_seats": ["N", "S", "W"],
    "can_manage": true,
    "playing": null
  }
}
```

409 responses:
```json
{"status": 409, "message": "You are already seated at a table. Leave it before creating another.", "data": []}
{"status": 409, "message": "You already have 3 active tables.", "data": []}
```

### `GET /tables/{table}`
One table (404 if the id doesn't exist), with `seats.user`, `free_seats` and
`can_manage` (no `playing`: that is only on `POST /tables`,
`POST /tables/{table}/start`, `POST /tables/{table}/seats` and
`POST /tables/{table}/seats/robots`).

### `POST /tables/{table}/seats`
Take a free seat at an existing table.

| Field | Rules | Default |
|---|---|---|
| `seat` | **required**, one of `N`, `E`, `S`, `W` | — |

- **201** with the updated table (same shape as `GET /tables/{table}`), plus
  `playing`: the caller's game state, exactly what
  [`GET /tables/{table}/playing`](#get-tablestableplaying) would answer, when
  the table has a playing after the request (you sat down at a table whose
  finished board is still on it, waiting for its seats to be refilled);
  otherwise `null`. Sitting down never deals: you start not ready, and the
  board waits for your Start.
- **Moving is allowed.** If you already hold a seat — at this table or another
  one — this request moves you rather than refusing. It is one transaction, so
  you never end up seated nowhere, and if the target seat turns out to be taken
  you keep the seat you had.
  - Moving off **another** table frees that seat with every usual consequence:
    the old table is deleted if you were its last player, `moderated_by` is
    handed on if it was yours, and an unfinished playing there is detached
    (`board_table.table_id` set to null, snapshot kept). The response describes
    only the table you joined; re-fetch `GET /tables` if the client tracks the
    old one.
  - Moving off a table **in the middle of a set** (your seat held after a
    Leave included) is walking out on it: **a robot takes your seat there
    at once** for the rest of the set (`replaced` reason `"moved"`, see
    [Away mid-set](#away-mid-set-and-the-turn-clock)), and you may not sit
    down at that table again until the set is over; the message then reads
    `"Seat taken successfully. You walked out on a set at your old table, so
    a robot took your seat there."`. Not for an admin, nor while an admin
    there is away, nor when you were the last human there: the set is then
    just abandoned. The SPA should confirm before moving a player who is
    mid-set (the old table's `set.finished` is false).
  - Moving **within** the same table is a plain seat change (e.g. `N` -> `E`).
    The table is not deleted on the way out even if you are its only player.
    Note a full table can never allow this, since every target seat is taken.
- Taking the **fourth** seat deals nothing: `board_id` stays null until
  every human at the table, you included, has pressed Start (see
  [Dealing](#tables)). Moving drops a Start you had pressed in your old seat.
- **409** if the seat is taken (`"Seat E is already taken."`) or the seat name
  is unknown, or if a robot took your seat over in the set going on at this
  table (`"You walked out on the set going on at this table: you may sit
  down here again once it is over."`). Already sitting somewhere is **no
  longer** a 409 — that is a move.
- **422** if `seat` is missing or not one of the four.
- **404** if the table id doesn't exist (tables are deleted when their last
  player leaves, so there is no "closed table" case).

### `DELETE /tables/{table}/seats`
Give up the seat you hold at this table. No body.

- **202** in the **middle of a set** (a board of it in progress, or between
  its boards): leaving is going away, and your seat is **held** rather than
  freed — see [Away mid-set](#away-mid-set-and-the-turn-clock). You stay
  seated with `away_since` set to now. Once the board waits for you (at
  once if it is your turn) your **turn clock** runs as anyone's does
  (`turn_deadline`, `BRIDGE_TURN_SECONDS`, 60): come back (any request at
  the table: heartbeat, `GET .../playing`, …) and play before it runs out,
  or a robot takes your seat for the rest of the set (`replaced` reason
  `"away"`). The body is the table (same shape as `GET /tables/{table}`),
  message `"You left in the middle of a set: your seat is held. Once the
  table is waiting for you, you have 60 seconds to play, or a robot takes
  your seat for the rest of the set."`. Leaving again changes nothing. The SPA should confirm before a
  Leave mid-set, and stop its heartbeat afterwards (a heartbeat brings you
  back). Not for an admin, nor while an admin at the table is away: then
  the seat is freed at once (below) and the set ends `abandoned`.
- **200** and the table is **deleted** if you were the last player:
  ```json
  {"status": 200, "message": "You left the table. Nobody was left, so the table was deleted.", "data": {"table_deleted": true}}
  ```
- **200** and the updated table (same shape as `GET /tables/{table}`) if
  players remain. If the leaver was `moderated_by`, the role passes to the
  **human** seated there longest (`table_seats.created_at`, then `id`) —
  never a robot, and the creator gets no preference; `created_by` is left
  alone. If only robots remain, the table becomes **unattended**
  (`unattended_since` set, `moderated_by: null`; see [Tables](#tables)).
  The seat you left stays free: no robot takes it.
- **409** `"You are not seated at this table."` if you hold no seat there.
- **404** if the table id doesn't exist.

If the table had a board under way that nobody had finished, the seat being
freed **abandons** it: `tables.board_id` goes back to null and the `board_table` row
is detached (`table_id` set to null) rather than deleted, keeping its
`board_table_seats` snapshot so those four players are never dealt that deal
again; the calls and cards made so far are deleted. A finished playing is
untouched. Once the table is refilled, everyone's Start deals a new board.

Leaving is what frees a user to create another table, since
`table_seats.user_id` is unique (`POST /tables` still 409s a seated user).
Joining another table doesn't need it: `POST /tables/{table}/seats` moves you
there, leaving your old seat in the same transaction. Either way a user holds
**one** seat at a time and nothing is reserved for them: moving to another
table gives the first seat up for good, and the first table may well be gone
(or full) by the time they come back.

### `POST /tables/{table}/seats/users`
A table manager puts **another** user into a free seat. A table has one
role, its moderator: the current `moderated_by` user (the creator when the
table is made; see [`DELETE /tables/{table}/seats`](#delete-tablestableseats)
for the handoff). Any `is_admin` user qualifies too, whether or not they sit
at the table. `created_by` grants nothing: a creator who left and came back
is a plain player (`App\Policies\TablePolicy::manage`, see
[`AUTH.md`](AUTH.md#authorization)).

| Field | Rules | Default |
|---|---|---|
| `user_id` | **required**, integer, an existing `users.id` | — |
| `seat` | **required**, one of `N`, `E`, `S`, `W` | — |

Find the `user_id` with [`GET /users?search=`](#get-userssearch), which also
flags users who are already `seated` somewhere (they would 409 here).

- **403** `"Only the table moderator or an admin can seat other
  players."` (Laravel's default `{message}` shape) for anyone else. This is
  checked before the body is validated.
- **201** with the updated table (same shape as `GET /tables/{table}`,
  message `"User seated successfully."`). Seating the **fourth** player deals
  nothing: the newcomer has to press Start themselves.
- **409** if the seat is taken (`"Seat E is already taken."`) or the user
  already sits at any table (`"That user is already seated at a table."`),
  or is [banned](#bans) (`"That user is banned."`).
  A full table always hits the taken-seat case.
- Unlike `POST /tables/{table}/seats`, this does **not** move a seated player.
  A manager has no authority over the table that player chose, and pulling them
  out would abandon that table's board for three other people, so it stays a
  409. A manager naming **themselves** in `user_id` is asking for themselves and
  is moved normally, the same way aiming `DELETE .../seats/{user}` at yourself
  is a quit rather than a kick.
- **422** if `user_id` is missing or unknown, or `seat` is missing or not one
  of the four.
- **404** if the table id doesn't exist (tables are deleted when their last
  player leaves, so there is no "closed table" case).

### `POST /tables/{table}/seats/robots`
A table manager (as for `POST /tables/{table}/seats/users`) puts a **robot**
in a free seat. The robot is the pool's first one that sits nowhere; a new
`robot-<n>` is made when they are all busy.

| Field | Rules | Default |
|---|---|---|
| `seat` | **required**, one of `N`, `E`, `S`, `W` | — |

- **403** `"Only the table moderator or an admin can seat a
  robot."` (Laravel's default `{message}` shape) for anyone else, checked
  before the body is validated.
- **201** with the updated table (same shape as `GET /tables/{table}`,
  message `"Robot seated successfully."`), plus `playing` as in
  `POST /tables/{table}/seats`. A robot is `ready` at once, so a robot taking
  the **fourth** seat deals the board if every human there has already
  pressed Start; `playing` is then the caller's game state, the new board's.
  Otherwise nothing is dealt.
- **409** if the seat is taken (`"Seat E is already taken."`).
- **422** if `seat` is missing or not one of the four.
- **404** if the table id doesn't exist.

Robots are sent away like any player, with `DELETE /tables/{table}/seats/{user}`.

### `DELETE /tables/{table}/seats/{user}`
Take a player out of their seat. No body. `{user}` is a `users.id`.

- If `{user}` is the logged-in user this is a **quit** and is always allowed.
  It is exactly `DELETE /tables/{table}/seats`, held seat (**202**) mid-set
  included.
- Otherwise it is a **kick** and requires `TablePolicy::kick`: the table's
  manager (`TablePolicy::manage`) may kick anyone — an admin may kick the
  moderator too, e.g. to stop cheating — and at an **unattended**
  table (only robots left) **any** logged-in user may kick a **robot**.
  Anyone else gets **403** `"Only the table moderator or an admin can
  remove other players."` (Laravel's default `{message}` shape).
- An **admin**'s seat (`user.is_admin`) is the exception: only another
  admin may kick them, never the moderator — **403** `"Only an admin can
  remove an admin."`. An admin is at a table to watch it or to act against
  cheating, so nobody they might be watching can send them away. Nothing
  automatic frees it either: not the idle timeout, not the away rule (see
  the heartbeat below), and an admin can't be banned.
- **200** and the updated table (same shape as `GET /tables/{table}`), with
  message `"You left the table."` for a quit or `"Player removed from the
  table."` for a kick. If the removed player was `moderated_by`, the role
  passes to the human seated there longest, as on a quit; `created_by` is
  left alone. Removing the last human
  leaves the table unattended, as `DELETE /tables/{table}/seats` does.
- **200** and the table is **deleted** if that was the last player:
  ```json
  {"status": 200, "message": "You left the table. Nobody was left, so the table was deleted.", "data": {"table_deleted": true}}
  ```
- A kick frees the seat at once, even mid-set, and abandons an unfinished
  playing just as a freed seat on `DELETE /tables/{table}/seats` does.
  Mid-set it ends the set `abandoned` — unless the kicked player was
  **away**: then a **robot takes their seat** for the rest of the set and
  plays the board on (`replaced` reason `"kicked"`; not when they were the
  last human there), and the message adds `" They were away mid-set, so a
  robot took their seat."`.
- **404** if the table id or user id doesn't exist, or if `{user}` holds no
  seat at this table — `"That user is not seated at this table."`, or
  `"You are not seated at this table."` when you aimed at yourself. (Note
  `DELETE /tables/{table}/seats` answers the same condition with a **409**;
  here the seat is addressed in the URL, so a missing one is a 404.)

A manager pointing this at themselves falls in the quit branch, so they leave
their own table rather than kicking themselves out of it. Being kicked is
**not** recorded: a kicked player is free to rejoin that same table or any
other straight away.

### `POST /tables/{table}/heartbeat`
A sign of life from a seated player. No body. The client sends it every
~30 s while the table is open (page visible or not), so the server can tell a
player who is still there from one who closed the tab.

- **200** `{"status": 200, "message": "Heartbeat received.", "data":
  {"last_seen_at": "2026-09-28T12:00:30.000000Z"}}` — the caller's
  `table_seats.last_seen_at`, just set to now.
- **403** for a caller who doesn't sit at this table (`TablePolicy::play`,
  Laravel's default `{message}` shape); nothing is recorded.
- **404** if the table doesn't exist.

The playing endpoints — `POST`/`DELETE /tables/{table}/start`,
`GET /tables/{table}/playing`,
`POST /tables/{table}/playing/next`, `POST /tables/{table}/calls` and the
two alert endpoints (`.../calls/{index}/question`, `.../explanation`),
both chat endpoints (`GET`/`POST /tables/{table}/messages`),
`POST /tables/{table}/cards` and the three claim endpoints — count as a heartbeat too (the `seen` route
middleware, `App\Http\Middleware\TouchTableSeat`), even when the call or
card itself is refused. Taking or changing a seat also sets it. These
endpoints and the heartbeat also expire an overdue claim before anything
else (see [Claims](#claims-post-tablestableclaim-post-tablestableclaimresponse-delete-tablestableclaim), "Silence means no").

**Keep beating while the tab is hidden.** The heartbeat is how the server
tells a player who is still there from one who has gone, and in the middle
of a set a player gone quiet is shown away to the others (below). So the
SPA must keep sending it while the page is hidden
(`document.visibilityState`), at least while the table is mid-set:
switching to another tab while partner thinks is not leaving. (Browsers may
throttle a hidden tab's timers to about once a minute after a few minutes;
that can show a player away briefly.) A heartbeat is not playing, though:
it never holds off the [turn clock](#away-mid-set-and-the-turn-clock),
only a call, a card or a claim action does. Stop it after a Leave, or it
brings you back.

**Idle seats are freed — outside a set.** The scheduled command
`tables:release-idle-seats` (every minute; see
[`RUNNING.md`](RUNNING.md#scheduler-idle-seats)) frees the seat of every
**human** player other than an admin (robots send no heartbeat and are
never idle; an admin's seat is only ever taken by another admin or
themselves) whose
`last_seen_at` is older than `BRIDGE_IDLE_SEAT_MINUTES` (default 5), at a
table that is **not** in the middle of a set (no set yet, or the last one
is over). It goes through `TableSeatService::remove()`, so it is exactly a
leave: `moderated_by` is handed on, the player's Start goes with their
seat, an emptied table is deleted, and `TableUpdated` is broadcast. A
client whose own seat vanished this way sees it in the next `TableUpdated`
(or a 403 from its next heartbeat) and should offer to rejoin. In the middle
of a set the away rule below decides instead.

#### Away mid-set, and the turn clock

During a set — a board of it in progress, or between its boards — the
player the board waits for has **one minute** to act. Letting it run out
takes them out of the set: a **robot takes their seat** and plays on with
their partner, so nobody else loses anything for it (`GAME-RULES.md` §8,
"Sets of boards"), whether they are away or sitting there with the tab
open.
`tables:check-away`, scheduled **every ten seconds** (one check every few
seconds rather than a delayed job per turn, so a player gets their minute
and at most ten seconds more), does it:

1. **Away.** A human with no sign of life for `BRIDGE_AWAY_SECONDS`
   (default 60, two missed heartbeats) is marked away: their seat's
   `away_since` is set to their `last_seen_at`. A `TableUpdated` tells
   the table. Their seat is **held** — nobody else can take it. Pressing
   **Leave** (`DELETE /tables/{table}/seats`) mid-set marks them away at
   once (`away_since` now). Any sign of life — a heartbeat or a playing
   request — clears `away_since`, with a `TableUpdated`.
2. **The turn clock.** The board waits on one player at a time: the game
   state's `acting_user_id` (declarer on dummy's turn, a robot declarer's
   human dummy on declarer's). That human has `BRIDGE_TURN_SECONDS`
   (default 60) from when the board began waiting for them — the deal
   (the dealer's first call), the previous call or card (the opening
   lead's turn too), or a claim cleared (rejected, withdrawn or expired) —
   to call, play a card or make a claim action (claim, answer, withdraw).
   The game state shows it as `turn_deadline`: on
   [`GET /tables/{table}/playing`](#get-tablestableplaying), the answers to
   calls, cards, claims and the Start that deals, and every
   [`PlayingUpdated`](#event-playingupdated). Each accepted move stops it
   and starts the next player's, and the turn already moves with every
   `PlayingUpdated`, so no `TableUpdated` comes per turn. Only a move resets
   it: a heartbeat, `GET .../playing`, a chat line, or asking about or
   explaining an alert does **not** — being there isn't playing. Nobody has
   a clock between boards (the next board comes by itself at
   `next_board_at`), while a claim is pending (it expires by itself), with
   no board, or when the board waits for a robot or an admin:
   `turn_deadline` is `null` then.
3. **Time is up.** Once `turn_deadline` has passed, the next check takes
   that player out and **a robot sits in their seat** for the rest of the
   set, ready. The set goes on and so does the board in progress: the
   robot takes the hand over where it is (the game state's `players` show
   it, and it makes the move that was waited for), and the board waits
   afresh (a new `turn_deadline` if a human is on turn). The set's
   `replaced` lists them, with reason `turn_timeout` (or `away` if they
   were away then, a Leave included). They may not sit down at that table
   again until the set is over (a 409 on `POST .../seats`). Moderation is
   handed on as on any leave, never to the robot. `TableUpdated` carries
   the robot's seat and the new `set.replaced`, `PlayingUpdated` the new
   `players`. If they declared and their partner, dummy, is a human, dummy
   plays both hands from then on and gets declarer's cards
   ([`DeclarerHandShown`](#event-declarerhandshown)). Only the player on
   turn can time out: another player away costs nothing however long they
   stay away (once the set is over their seat is freed, below). If no other
   human is left at the table, a robot would have nobody to play with: the
   set ends `abandoned` instead, the board in progress is abandoned
   unscored, and the table is left to its robots (unattended).
4. **After the set.** Once a set is over, however it ended, nobody is held
   for it: the next check frees the seat of anyone still away (a Leave
   that was never taken back, say).

**Moving** to another table mid-set hands the seat to a robot at once
(`moved`), and so does a manager **kicking** a player who is away
(`kicked`, as does an admin's ban mid-set). **Robots** never have a clock
and are never away. A set is never forfeited: whoever walks out is
replaced, and the set's result stands for the seats as they end it.

`set.replaced` (in the game state, the table and `GET /sets/{set}`) is the
list of players a robot took a seat over from, in seat order:
`{seat, user_id, reason}`, `reason` one of `turn_timeout`, `away`, `moved`
or `kicked`, so a client can word it ("East didn't play in time: a robot
took their seat"). It is `[]` while the four who opened the set are all
still there. That a player walked out isn't counted anywhere else yet
(player stats are #121).

**Admins** are never replaced: an admin has no turn clock (the table just
waits for them), an admin who goes quiet is shown away but their seat is
never freed for it. While an admin at the table is away, a Leave or a move
by anyone else is immediate and ends the set `abandoned`; but the player on
turn still has their clock, and running out of time on their own turn
still hands their seat to a robot. An admin's own Leave or move is
immediate as well and ends the set `abandoned`. Once the set is over an
away admin just stops being away, and keeps the seat: the idle timeout
above skips admins too.

Outside a set nothing of this applies: nobody is marked away, Leave frees
the seat at once, and the idle timeout above is the only one.

The server can't learn about a disconnect from the websocket: Reverb doesn't
call back into Laravel when a connection drops, so the heartbeat — not a
presence channel — is what decides.

### `POST /tables/{table}/start`
The caller is ready to play. No body. The board is dealt once the table is
**full** and **every human** seated there has pressed Start; robots are
ready from the moment they sit down (see [Dealing](#tables)). Built by
`Game\TableStartController@store` over `BoardSelectionService::start()`,
which takes the table row lock like seat changes do.

- **200**, the table (same shape as `GET /tables/{table}`) plus `playing`:
  - message `"Ready: waiting for the other players."` while the table is
    short or somebody has still to press — the caller's seat now `ready`,
    `board_id` unchanged, `playing` `null` (or the finished board still on
    the table, as in `POST /tables/{table}/seats`);
  - message `"Board dealt."` when this Start deals — the first board of a
    new [set](#sets) (`playing.set.board` is 1) — `board_id` set and
    `playing` the caller's game state of the new board, exactly what
    [`GET /tables/{table}/playing`](#get-tablestableplaying) would answer,
    so no request is needed after it.
- Pressing again changes nothing (same 200, no event).
- The board is chosen by the rule in
  [`GAME-RULES.md` §8](GAME-RULES.md#board-selection-rule): one none of the
  four has played, else one where nobody holds a seat they have held on it
  before, else a freshly shuffled board. No request ever fails for want of a
  board. Dealing opens the playing (`board_table` + four `board_table_seats`
  rows) and clears every human's Start.
- Broadcasts `TableUpdated` when the caller is newly ready (so everyone sees
  who has pressed), and with it, when it deals, `PlayingUpdated` and one
  `HandDealt` per human — what a dealt board always sends.
- **409** with the reason in `message`:
  - `"A board is already in progress at this table."` — in its auction or
    play;
  - `"The board is finished: the next board of the set is dealt by itself
    shortly (POST /tables/{table}/playing/next deals it at once)."` — a
    finished board is on the table, its set isn't over, and the four who
    played it are all still in their seats (the state's `next_board_at` says
    when the next board comes). If one of them has been replaced, or the board was the set's last,
    Start is what deals the next board, and this 409 goes away;
  - `"You are not seated at this table."` — you left between the policy
    check and the lock.
- **403** for a caller who doesn't sit at this table (`TablePolicy::play`,
  Laravel's default `{message}` shape) — an admin or a manager included:
  nobody presses Start for somebody else.
- **401** for a guest, **404** if the table doesn't exist.

### `DELETE /tables/{table}/start`
Take your Start back while no board is dealt. No body.

- **200**, message `"Start withdrawn."`, the table (no `playing`). Taking
  back a Start you hadn't pressed is a 200 too, with no event; otherwise
  `TableUpdated` is broadcast.
- **409**, **403**, **401** and **404** as for `POST`.

## Playing (game state)

Once the table is full and everyone has pressed Start, a board is dealt and
the table has a
**playing** (a `board_table` row). `GET /tables/{table}/playing` is its read
side; `POST /tables/{table}/calls` runs the auction,
`POST /tables/{table}/cards` the play and `/tables/{table}/claim` ends the
play early by agreement. A finished board carries its
duplicate score in `result` and the whole deal in `deal`, and stays on the
table until the set's next board is dealt — by itself at `next_board_at`, or
earlier with `POST /tables/{table}/playing/next` — or, after the set's last
board, until everyone's Start.

### `GET /tables/{table}/playing`
One snapshot a client can render the table from scratch with, e.g. after a
page refresh or a reconnect. No body. Built by
`App\Services\PlayingStateService::stateFor()`; the public part is
`App\Http\Resources\PlayingResource`.

- **200** for a player **seated at this table** (the same audience as the
  `private-table.{id}` channel), message `"Playing retrieved successfully."`
- **403** (Laravel's default `{message}` shape) for anyone else, including a
  player seated at another table — `TablePolicy::play`.
- **401** for guests, **404** for an unknown table id.

```json
{
  "status": 200,
  "message": "Playing retrieved successfully.",
  "data": {
    "phase": "auction",
    "playing_id": 42,
    "set": {"id": 5, "number": 1, "board": 2, "of": 4, "finished": false, "ended": null, "replaced": []},
    "board": {"id": 7, "number": 7, "dealer": "S", "vulnerable": "N-S E-W"},
    "players": {
      "N": {"id": 1, "name": "Ann", "username": "ann", "is_robot": false, "is_admin": false},
      "E": {"id": 2, "name": "Bob", "username": "bob", "is_robot": false, "is_admin": false},
      "S": {"id": 3, "name": "Cy", "username": "cy", "is_robot": false, "is_admin": false},
      "W": {"id": 9, "name": "Robot 1", "username": "robot-1", "is_robot": true, "is_admin": false}
    },
    "turn": "N",
    "acting_user_id": 1,
    "turn_deadline": "2026-09-22T10:20:31.000000Z",
    "auction": [
      {"seat": "S", "bid": {"id": 6, "call": "1H", "level": 1, "strain": "H", "special": false}},
      {"seat": "W", "bid": {"id": 1, "call": "P", "level": null, "strain": null, "special": true}}
    ],
    "contract": null,
    "tricks": null,
    "current_trick": null,
    "tricks_won": null,
    "dummy_hand": null,
    "claim": null,
    "claim_locked": false,
    "result": null,
    "deal": null,
    "ready": null,
    "next_board_at": null,
    "my_seat": "E",
    "hand": [
      {"id": 52, "suit": "S", "rank": 15, "rank_name": "Ace"},
      {"id": 47, "suit": "S", "rank": 9, "rank_name": "9"},
      {"id": 38, "suit": "H", "rank": 13, "rank_name": "Queen"}
    ],
    "declarer_hand": null
  }
}
```

| Field | Meaning |
|---|---|
| `phase` | `waiting` — the table has no board (`tables.board_id` null, fewer than four players); `auction` — `board_table.auction_ended_at` is null; `play` — the auction ended with a contract (`finished_at` still null); `finished` — `finished_at` is set: after the 13th trick, when a claim is accepted, or straight away on a **passed out** board. |
| `playing_id` | the `board_table.id` |
| `set` | the [set](#sets) this board was dealt in: `id` (for [`GET /sets/{set}`](#get-setsset)), `number` (1, 2, 3… at this table), `board` (this board's place in it, 1–`of`), `of` (how many boards the set has, 4), `finished` (true once the set is over: after its last board is finished, or earlier if one of its four left), `ended` (`null` while it goes on, then `completed` or `abandoned`) and `replaced` (the players a robot took a seat over from mid-set, `[{seat, user_id, reason}]`, `[]` for none; see [Away mid-set](#away-mid-set-and-the-turn-clock)). After the last board `finished` is true while that board is still on show: time for the set's results and everyone's Start |
| `board` | `id`, `number`, `dealer` (`N/E/S/W`) and `vulnerable` (a `Vulnerability` value) from `boards` |
| `players` | seat → public profile less `description` (`PlayerResource`, as a seat's `user`: no email; `is_robot` marks a robot), from the playing's `board_table_seats` snapshot, not from `table_seats` — so a robot that took a seat over mid-board shows here from then on |
| `turn` | the seat expected to act. During the `auction`: the dealer first, then clockwise after the last call. During the `play`: the **hand** the next card comes from — declarer's left-hand opponent leads the first trick, then clockwise, and each trick's winner leads the next. When it is dummy's seat, declarer plays it (see `acting_user_id`). `null` while `waiting` and once `finished` |
| `acting_user_id` | the id of the user who must act for `turn`: that seat's player, except that on dummy's turn it is **declarer**. One exception to that: when a **robot declares and dummy is a human**, the human plays both hands, so on declarer's turn **and** on dummy's turn it is the **human dummy's** id, and the robot declarer never acts in the play (see [`declarer_hand`](#get-tablestableplaying) and [`POST /tables/{table}/cards`](#post-tablestablecards)). Declarer and dummy themselves don't change (`contract`). A client compares it with its own user id to know it is its move (and, when `turn` isn't its own seat, that it is playing its partner's cards). `null` whenever `turn` is |
| `turn_deadline` | when the [turn clock](#away-mid-set-and-the-turn-clock) of `acting_user_id` runs out (ISO 8601, like `claim.expires_at`): `BRIDGE_TURN_SECONDS` (60) after the board began waiting for them — the deal, the previous call or card, or a claim cleared. Past it, `tables:check-away` takes them out and a robot plays their seat for the rest of the set (`set.replaced` reason `"turn_timeout"`, or `"away"`). Only a call, card or claim action moves it; a heartbeat or a chat line doesn't. `null` whenever nobody's clock runs: `waiting`, `finished` (between boards), a claim pending, or `acting_user_id` a robot or an admin. Count down from it, never from when the state arrived. In `PlayingUpdated` too; not in `GET /playings/{playing}` |
| `auction` | the calls made so far, in order: `{seat, bid, alert, question}`, where `bid` is `{id, call, level, strain, special}` — `call` is the short name (`P`, `X`, `XX`, `1C`…`7NT`) and the only field telling pass, double and redouble apart; `level`/`strain` are null for those three. `[]` before the first call. A call's place in this list (from 0) is its `index` in the [alert](#alerts) endpoints and events. `alert` and `question` are **per viewer** ([Alerts](#alerts)): for the caller's own calls and the opponents', `alert` is `{explanation}` (`explanation` a string, or null for "alerted, no description") once the call is alerted, else null, and `question` is `{asked_by}` (the asking opponent's seat) while a question about it is open, else null; for **partner's** calls both are null during the auction — partner seeing them would be unauthorised information. Once the auction is over (phase `play`) every call's `alert` shows to all four players, partner's included, while partner's `question` stays null. Once the board is `finished`, every call's `alert` shows, to everyone, and `question` is null. Neither field is ever on the table channel (`PlayingUpdated`) |
| `contract` | `null` during the auction and on a passed out board; once the auction ends with a bid, `{bid, doubled, declarer, dummy}` — `bid` shaped as above, `doubled` 0 (none), 1 (X) or 2 (XX), `declarer` the seat of the first player on the winning side to name the strain, `dummy` declarer's partner |
| `tricks` | the **complete** tricks, in order: `{round, leader, cards, winner}` — `round` 1–13, `leader` the seat that led, `cards` the four `{seat, card}` in the order played (`seat` is the hand the card came from, so dummy's seat for dummy's cards), `winner` the seat whose card won. `[]` until the first trick is complete. `null` whenever `contract` is |
| `current_trick` | the trick in progress, as `{seat, card}` in the order played: `[]` before the opening lead and between a trick's 4th card and the next lead (the finished trick is then the last of `tricks`). `null` whenever `contract` is |
| `tricks_won` | `{ns, ew}`: complete tricks won by each side so far. `null` whenever `contract` is |
| `dummy_hand` | dummy's **remaining** cards, face up to all four players (and on the table channel) once the opening lead is made; `null` before it, and whenever `contract` is. Same order and card shape as `hand`. Declarer plays these cards; dummy's own `hand` shows the same cards |
| `claim` | the **pending** claim (see [Claims](#claims-post-tablestableclaim-post-tablestableclaimresponse-delete-tablestableclaim)), else `null`: `{seat, tricks, hand, accepted, expires_at}` — `seat` the claimer, `tricks` how many of the remaining tricks they claim for their side (`0` is a concession), `hand` the claimer's **remaining cards, face up to everyone** (on the table channel too) while the claim is pending, in `hand`'s order and card shape, `accepted` the seats that have accepted it so far, in N, E, S, W order (`[]` at first), and `expires_at` (ISO 8601, like `turn_deadline`) when silence rejects it: `BRIDGE_CLAIM_SECONDS` (10) after it was made. Count down from `expires_at`, never from when the claim reached the client. While it is non-null no card may be played; `turn` and `acting_user_id` don't change. Back to `null` once the claim is rejected, withdrawn, expired or accepted (the board is then `finished` and `result.claimed` is `true`) |
| `claim_locked` | `true` from a claim's ending **without** being accepted (rejected, withdrawn or expired) until the next card is played: nobody may claim meanwhile (409, see [Claims](#claims-post-tablestableclaim-post-tablestableclaimresponse-delete-tablestableclaim)), so hide Claim and Concede. Otherwise `false`, also while `waiting` |
| `result` | `null` until the phase is `finished`. Then `{contract, doubled, declarer, tricks_won, score_ns, made_by, claimed}`: `contract` is the final bid (shaped as `bid` above), `doubled` 0/1/2, `declarer` its seat, `tricks_won` the tricks declarer's side took, `score_ns` the duplicate score (`GAME-RULES.md` §6) **from N-S's point of view** — positive when N-S scored, negative when E-W did, whichever side declared — `made_by` the overtricks (`+1`), `0` for just made, or undertricks (`-2`), and `claimed` whether the play ended by an accepted claim rather than at trick 13 (`tricks_won` then includes the claimed tricks). A **passed out** board has `score_ns: 0`, `claimed: false` and every other field `null`. Example: `{"contract": {"id": 22, "call": "4S", ...}, "doubled": 0, "declarer": "E", "tricks_won": 11, "score_ns": -650, "made_by": 1, "claimed": false}` — E-W vulnerable, 4♠ by East making 11 |
| `deal` | `null` until the phase is `finished`. Then all four hands **as dealt** (from `board_card`, not what is left after the play): `{N: [...], E: [...], S: [...], W: [...]}`, each in `hand`'s order and card shape. Public — it is on the table channel too — since the board is over |
| `ready` | `null` until the phase is `finished`. Then the seats whose players have asked for the next board (`POST /tables/{table}/playing/next`), in N, E, S, W order: `[]` right after the board ends |
| `next_board_at` | when the set's next board is dealt **by itself** (ISO 8601, like `claim.expires_at`): `BRIDGE_NEXT_BOARD_SECONDS` (10) after the board finished. `null` until the phase is `finished`, and whenever no automatic deal is coming: the set is over (its last board, or abandoned), a seat is empty, or the four seated aren't the four who played the board (everyone's Start deals the next one then). Count down from it, never from when the state arrived. Not in `GET /playings/{playing}` |
| `my_seat` | the caller's seat in the snapshot |
| `hand` | the caller's **own** cards only: the 13 `board_card` rows for their seat, less any card already in `cardplays`, sorted spades, hearts, diamonds, clubs and high to low within a suit. Card `rank` is 2–10, J=12, Q=13, K=14, A=15. Apart from this and `declarer_hand`, the only cards in the payload are face up: those in `tricks` / `current_trick`, after the opening lead `dummy_hand`, a pending claim's `claim.hand`, and once the board is finished `deal` |
| `declarer_hand` | **only** for a human dummy whose declarer is a robot, who plays declarer's cards (see `acting_user_id`): declarer's **remaining** cards, in `hand`'s order and card shape, from the end of the auction (all 13, before the opening lead) to the end of the play. `null` for everyone else and at any other time, including once the board is `finished` (`deal` then shows it). Private to that player like `hand`: it is in their own answers only (this endpoint and the answers to calls, cards, claims, Start and Next), never on the table channel or in anyone else's state — the defenders see only dummy's cards after the lead. The auction usually ends on a robot's call, so it is also pushed as [`DeclarerHandShown`](#event-declarerhandshown) |

A card is `{id, suit, rank, rank_name}` everywhere it appears.

While `phase` is `waiting`, **every other field is null** (including `hand`,
`declarer_hand`, `my_seat` and `auction`).

### `POST /tables/{table}/playing/next`
An optional **"deal now"**. Once a board is `finished` (13 tricks played, a
claim accepted, or passed out) and its [set](#sets) isn't over, the set's
next board is dealt **by itself** at the state's `next_board_at`,
`BRIDGE_NEXT_BOARD_SECONDS` (default **10**) after the board finished — the
finished board stays on the table, `result` and the whole `deal` on show,
until then. Nobody has to press anything. This endpoint deals it **earlier**:
once **every human** at the table has asked, the last one to ask deals it at
once. Robots count as having asked, so a human alone with three robots skips
the wait with one request. Built by
`App\Services\BoardSelectionService::moveOn()`.

No body. Every player asks for themselves (robots ask too, as soon as the
board ends, though they never hold the deal up); nobody — not the moderator,
not an admin — can ask for anyone else.
The `everyone` field this endpoint used to take is **ignored**: sending it
only asks for the caller, like any other request.

- **200** with the game state, exactly what `GET /tables/{table}/playing`
  would now return:
  - message `"Waiting for the other players."` while a human has still to
    ask — still the `finished` board, with the caller's seat now in `ready`;
  - message `"Next board dealt."` once the last human asks — phase
    `auction` on the new board, with the caller's new `hand`.
  Asking again changes nothing (same 200, no broadcast).
- **409** (`sendError`, `data: []`), nothing stored:
  - `"The board is not finished yet."` — the phase is `auction` or `play`;
  - `"The table has no board yet: ..."` — the phase is `waiting`;
  - `"The table is short of a player: ..."` — somebody left after the board
    ended; there's nothing to confirm: once a fourth player sits down, the
    next board is dealt by everyone's Start (as for the first board);
  - `"The players have changed since this board: ..."` — the table is full
    again, but not with the four who played the board: everyone presses
    Start ([`POST /tables/{table}/start`](#post-tablestablestart)) instead;
  - `"The set is over: press Start for a new one."` — the board was the set's
    last (`set.finished` is true): no fifth board is dealt this way, the
    finished board stays on show, and everyone's Start opens the next set.
    Robots don't ask then, so `ready` stays `[]`.
- **403** `"Only the players seated at this table can ask for the next
  board."` for anyone not seated at this table, admins included; **401** for
  guests, **404** for an unknown table id.

The next board is picked by the same rule as the first (`GAME-RULES.md` §8:
none of the four has played it, else nobody has played it from the seat they
hold, else a freshly shuffled one; never a board this table has played),
for the same four players in the same seats. `tables.board_id` moves to it
and a new `board_table` row and seat snapshot are opened; the finished one
stays as it is. It sends what the last Start sends:
`TableUpdated`, `PlayingUpdated` and one `HandDealt` per player — whether the
last human's request deals it or the timer does. A player newly asking sends
`PlayingUpdated` (for `ready`).

**The timer.** When a board of a set other than its last finishes,
`BoardTable::finish()` queues the job `App\Jobs\DealNextBoard` with a delay up
to `next_board_at` (so it needs `php artisan queue:work`). It deals through
the same rules and lock as this endpoint
(`BoardSelectionService::dealNext()`), and does **nothing** if by then the
table has moved on (everyone asked, or it ran twice), a player left or the
four seated aren't the ones who played the board (the next board then takes
everyone's Start), or the set ended (abandoned). A robot that took a seat
over between boards counts as one of the four, and a player it replaced
doesn't hold up the others' Next. It deals even while a seat
is [away](#away-mid-set-and-the-turn-clock): being dealt to is not a sign of
life, and once the board waits for them their turn clock runs as anyone's.

Who has asked is `board_table_seats.ready_at` on the finished playing (not
to be confused with `table_seats.ready_at`, Start). Leaving between boards
detaches nothing (the playing is finished): the table keeps showing the
finished board until a fourth player sits down and everyone has pressed
Start.

### `POST /tables/{table}/calls`
Make the caller's next call in the auction. Built by
`App\Services\AuctionService::call()`; the rules are in
[`GAME-RULES.md`](GAME-RULES.md) §4.

| Field | Rules |
|---|---|
| `bid_id` | required; one of the 38 `bids` rows (`P`, `X`, `XX`, `1C`…`7NT`), whose ids [`GET /bids`](#get-bids) lists |

- **201** with the updated state — exactly what `GET /tables/{table}/playing`
  would now return, `hand` and `my_seat` included — message
  `"Call made successfully."`
- **409** (`sendError`, `data: []`) when the call can't be made, with the
  reason as the message:
  - `"It is not your turn: N calls next."` — the dealer calls first, then
    clockwise;
  - `"1D is not higher than the last bid, 1H."` — a bid must outrank the last
    bid (level, then strain ♣ < ♦ < ♥ < ♠ < NT);
  - `"There is no bid to double."`, `"You can't double your own side's bid."`,
    `"That bid is already doubled."`, `"That bid is already redoubled."` — X
    only on an opponent's bid, and only while that bid is the last call other
    than a pass;
  - `"Only a double can be redoubled."`,
    `"You can't redouble your own side's double."`,
    `"That bid is already redoubled."` — XX only on an opponent's X, and only
    while it is the last call other than a pass;
  - `"The auction has ended."` — the phase is `play` or `finished`;
  - `"The table has no board yet: ..."` — the phase is `waiting` (fewer than
    four players, or a player left and abandoned the board).
- **422** (default Laravel shape) for a missing or unknown `bid_id`, an
  `alert` that isn't a boolean, or an `explanation` over 200 characters.
- **403** for anyone not seated at this table (checked before validation),
  **401** for guests, **404** for an unknown table id.

Two optional fields alert the call (a **self-alert**, see [Alerts](#alerts)):

| Field | Rules |
|---|---|
| `alert` | boolean, default false: the call is conventional or artificial |
| `explanation` | nullable string, max 200 characters, plain text: what it means. A non-empty one alerts the call by itself; blank counts as none |

An alert with no explanation is allowed ("alerted, no description"); the
opponents may ask for one. It is stored on the call (`auctions.alerted`,
`auctions.explanation`), shows on that call's `alert` in the state of the
caller and of the two opponents, never partner's during the auction, and is
pushed to the human opponents as [`CallAlerted`](#event-callalerted). Once
the auction is over partner sees it too
([`AuctionAlertsShown`](#event-auctionalertsshown)). `PlayingUpdated` carries
nothing of it.

A pass is always legal. The auction ends after three passes following a bid,
X or XX, or after four passes from the start. It then writes the result on
`board_table`: `contract_bid_id`, `doubled`, `declarer_seat`, `declarer_id`
(from the `board_table_seats` snapshot) and `auction_ended_at`, and the phase
becomes `play`. Four passes **pass the board out**: `auction_ended_at` and
`finished_at` are both set, no contract is saved and the phase becomes
`finished`, with `score = 0` and `tricks_won` null (`result.score_ns` is 0).

The `board_table` row is locked for the whole call, so two players calling at
once can't both land as "the next call": the second one is checked against
the first. Every accepted call sends `PlayingUpdated`; a refused one sends
nothing and stores nothing.

### Alerts

Alerts are **self-alerts**, as online: the bidder marks their own call as
conventional (`alert` on [`POST /tables/{table}/calls`](#post-tablestablecalls))
and may say what it means. **The opponents see it, partner doesn't**, until
the auction is over — from then on the auction's meaning is no longer
unauthorised information for anyone:

- during the auction the caller's game state (`GET /tables/{table}/playing`
  and every action's answer) has `alert` and `question` on each `auction`
  entry for the caller's own calls and the opponents', null for partner's;
- each alert or answer made during the auction is pushed to the bidder's
  two opponents (the humans) as [`CallAlerted`](#event-callalerted) on their
  own channel;
- once the auction is over (phase `play`, or the passed out board's
  `finished`) every call's `alert` is in all four players' state, partner's
  included; `question` stays per viewer (null on partner's calls). Each
  human whose partner alerted something gets those alerts once, as
  [`AuctionAlertsShown`](#event-auctionalertsshown), since the auction
  usually ends on a robot's call; an answer given during the play goes to
  all four humans as `CallAlerted`;
- `PlayingUpdated`, on the table channel, carries no alert data at all, not
  even the flag;
- once the board is `finished` every alert is public: in everyone's state at
  the table and in the review (`GET /playings/{playing}`).

Every question and every answer also goes into the board's [chat](#chat),
to the bidder's opponents — in the play too, so partner reads them there
only once the board is finished (`to: "opponents"`, `call_index` the call): the
question from the asker as `"What does 1C mean?"` (`Pass`, `Double`,
`Redouble` for the special calls), the answer from the bidder — a robot
too — as its explanation. So a client may show the conversation in the
chat alone and keep `alert`/`question` for the auction box.

Robots alert their conventional calls themselves (Stayman, transfers, the
strong 2♣ …, listed in [`ROBOTS.md`](ROBOTS.md)), with their system's
explanation.

`{index}` below is the call's place in `auction`, from 0. Both endpoints work
from the first call until the board is `finished` (through the play too),
for the players of the board (its seat snapshot) — in the play too, only
the bidder's opponents may ask; both lock the
`board_table` row like a call does.

#### `POST /tables/{table}/calls/{index}/question`

Ask what a call of **the other side** means, alerted or not. No body.

- **200** with the updated state. A **robot** bidder answers at once, with
  what its system reads into that call ("Natural" when no rule makes it):
  the call is alerted with that explanation, `CallAlerted` goes to the
  asker's side (to all four humans in the play), message `"Question answered."`. A **human** bidder gets
  [`CallQuestioned`](#event-callquestioned) and the call's `question` is
  `{asked_by}` (the asker's seat) for the bidder and both opponents until
  they answer, message `"Question asked: waiting for the answer."`.
- **409** with the reason: `"Ask the opponents about their own calls: that
  call is your side's."`, `"N has asked about that call already: wait for
  the answer."` (one open question per call; once it is answered it may be
  asked again), `"There is no call 5 in the auction."`, `"The board is over:
  every alert is public now."`, `"The table has no board yet."`, `"You are
  not playing this board."`.
- **403** for anyone not seated at this table, **401** for guests, **404**
  for an unknown table or a non-numeric `{index}`.

#### `PUT /tables/{table}/calls/{index}/explanation`

The bidder explains their **own** call: the answer to an open question, a
fix to their alert's explanation, or an alert they forgot to make.

| Field | Rules |
|---|---|
| `explanation` | required string, max 200 characters, plain text |

- **200** with the updated state, message `"Call explained."`: the call is
  alerted with this explanation, its question (if any) is closed, and both
  opponents get it as `CallAlerted` — partner doesn't during the auction; in
  the play all four humans do.
- **409**: `"Only its bidder can explain a call."`, and the call/board
  reasons above.
- **422** for a missing, blank or too long `explanation`; **403**, **401**,
  **404** as above.

### Chat

A chat per board, kept with the board (`board_messages`,
`App\Services\BoardChatService`), for **everyone** seated at the table, in
every phase: players greet each other, wish good luck, apologise, ask the
other side about their bidding or their carding ("fourth best, or third
and fifth?", "is that count?") and answer. A message goes `to`:

- **`table`**: all four read it — the sender's partner and both
  opponents — in `auction`, `play` and once the board is `finished`. This
  is open table talk.
- **`opponents`**: the sender and their two opponents read it, **never the
  sender's partner**, until the board is finished: for asking the other
  side about their agreements and answering without partner reading along,
  which would be unauthorised information.

There is **no partner-only** message: anything partner reads, the
opponents read too.

Messages belong to the table's current board: the one in play, or the last
one finished until the next is dealt. Before the first deal (`waiting`)
there is nothing to attach them to, so the chat opens with the first
board. **Once the board is `finished`, all of its messages are visible to
all four** (an `opponents` message then reaches everyone too), and the
review (`GET /playings/{playing}`) has them as `messages`.

A message with a `call_index` about a call of **the other side** is a
question. If that call was a **robot's**, the robot answers at once in the
chat with what its system reads into the call (`"Transfer: 0–17 HCP, 5+ ♠,
asks partner to bid ♠"`), from its own seat, `to` as the question was, with
the same `call_index`. A message with a `card_index` about a card of the
other side is a question about the card play the same way: if a **robot
defender** played it, the robot answers with its carding agreement for
that kind of card, with the same `card_index` — an opening lead
(`"Opening lead: the top of a sequence (A-K, K-Q, Q-J, J-10, 10-9);
otherwise from the longest suit, fourth best from four or more cards, …"`),
a later lead, attitude on partner's lead, count on declarer's, a ruff or a
discard ([`ROBOTS.md`](ROBOTS.md#answering-in-the-chat)). Declarer's and
dummy's cards carry no agreement, so a robot declarer doesn't answer. A
human answers by sending a message themselves. Robots don't otherwise
chat. A chat message changes nothing on
the call itself (its `alert`, `question`): that is what the
[Alerts](#alerts) endpoints are for, and those write their question and
answer into the chat as well.

Messages are kept: a banned user's existing ones stay, but a ban stops them
sending (`not-banned`, 403).

One message, in every payload (`App\Http\Resources\BoardMessageResource`):

```json
{
  "id": 12,
  "seat": "E",
  "user_id": 5,
  "to": "opponents",
  "call_index": 2,
  "card_index": null,
  "body": "What does 2♥ show?",
  "created_at": "2026-10-05T12:00:00.000000Z"
}
```

`seat` is the sender's seat (the client names them from `players`),
`call_index` the call's place in `auction` (from 0) or null, `card_index`
the card's place in the play (from 0, trick by trick, as `tricks` lists
them) or null; at most one of the two is set.

#### `GET /tables/{table}/messages`

- **200**, message `"Messages retrieved successfully."`, `data`
  `{playing_id, messages}`: the current board's playing (null before the
  first deal, with `messages: []`) and the messages **the caller may
  read**, oldest first — during the board, every `table` message and
  every `opponents` message but partner's; once it is finished, all of
  them.
- **403** for anyone not seated at this table, and for a banned user;
  **401** for guests; **404** for an unknown table.

#### `POST /tables/{table}/messages`

| Field | Rules |
|---|---|
| `body` | required string, 1–500 characters after trimming, plain text (render it as text, never as HTML) |
| `to` | required, `opponents` or `table` |
| `call_index` | optional integer from 0: the call of the current auction the message is about |
| `card_index` | optional integer from 0: the card of the current play the message is about; not with `call_index` |

- **201**, message `"Message sent."`, `data` the message. It goes out as
  [`BoardMessageSent`](#event-boardmessagesent) to every human who may read
  it, the sender included — never on the table channel.
- **409** `"The table has no board yet: the chat opens with the first
  deal."` before the first deal. Either `to` is accepted in every phase
  once there is a board.
- **422** for a missing, blank or too long `body`, a `to` that isn't one of
  the two, a `call_index` that isn't a call of the current auction
  (`"There is no call 5 in the auction."`), a `card_index` that isn't a
  card played yet (`"There is no card 5 in the play."`), or both indexes
  at once.
- **403** for anyone not seated at this table, and for a banned user (the
  ban's envelope, as on every game action); **429** past 10 messages in 30
  seconds (per user); **401** for guests; **404** for an unknown table.

### `POST /tables/{table}/cards`
Play the next card of the current trick. Built by
`App\Services\CardPlayService::play()`; the rules are in
[`GAME-RULES.md`](GAME-RULES.md) §5.

| Field | Rules |
|---|---|
| `card_id` | required; one of the 52 `cards` rows |

Each player plays their own hand, **except dummy's, which declarer plays**:
on dummy's turn (`turn` is dummy's seat, `acting_user_id` is declarer's id)
declarer sends one of dummy's cards. Dummy's own player never plays — unless
declarer is a **robot and dummy a human**: then it is the other way round,
the human dummy sends the card on declarer's turn (from `declarer_hand`) and
on their own, and the robot declarer never plays. In both cases the caller
sends the card for `turn` and must be `acting_user_id`. The row stored in
`cardplays` has the caller as `user_id` and the hand the card came from as
`seat`, plus `round` (trick 1–13) and `order` (1–4). Playing the partner's
card is a sign of life like playing one's own (`seen`), so the idle and away
timers treat it the same; while either hand of that side is to play, the
table waits on the human dummy.

- **201** with the updated state — exactly what `GET /tables/{table}/playing`
  would now return, `hand` and `my_seat` included — message
  `"Card played successfully."`
- **409** (`sendError`, `data: []`) when the card can't be played, with the
  reason as the message:
  - `"It is not your turn: E plays next."` — names the hand to play from
    (dummy's seat when declarer should play from dummy);
  - `"Dummy doesn't play: declarer plays dummy's cards."` — the caller is
    dummy, whatever the turn (but see the robot declarer exception above);
  - `"Your partner, dummy, plays declarer's cards."` — the caller is a robot
    declarer whose dummy is a human (a robot never sends this request; the
    services refuse it all the same);
  - `"That card is not in the hand being played."` — it was dealt to another
    seat (including declarer trying their own card on dummy's turn);
  - `"That card has already been played."`;
  - `"You must follow suit: Spades were led."` — the hand still holds the suit
    led; a hand out of it may play anything, trump included;
  - `"The auction is not over yet."`, `"The board is finished."`, or
    `"The table has no board yet: ..."` — the phase isn't `play`;
  - `"N has claimed: no card may be played until the claim is rejected or withdrawn."`
    — a claim is pending (`claim` is non-null).
- **422** (default Laravel shape) for a missing or unknown `card_id`.
- **403** for anyone not seated at this table (checked before validation),
  **401** for guests, **404** for an unknown table id.

After a trick's 4th card, the winning card's row gets `won_trick = true`: the
highest trump if any was played, else the highest card of the suit led (NT
has no trump). Its hand leads the next trick. After the 13th trick
the board is scored (`App\Services\ScoringService`, `GAME-RULES.md` §6):
`board_table.tricks_won` (declarer's side), `score` (from N-S's side) and
`finished_at` are written in `BoardTable::finish()`, the phase becomes
`finished` and the last card's `PlayingUpdated` (and its 201 response)
carries `result`.

The `board_table` row is locked for the whole request, so two cards sent at
once are checked one after the other. Every accepted card sends
`PlayingUpdated`; a refused one sends nothing and stores nothing.

### Claims: `POST /tables/{table}/claim`, `POST /tables/{table}/claim/response`, `DELETE /tables/{table}/claim`
End the play early by agreement (`GAME-RULES.md` §5). Built by
`App\Services\ClaimService`. During the `play`, any player **except dummy**
may claim a number of the tricks still to play for their side; the other two
non-dummy players must all accept it — both defenders for declarer's claim,
declarer and the other defender for a defender's. Dummy's own player can
neither claim nor answer — except a **human dummy whose declarer is a
robot**, who plays declarer's cards and so claims, answers and withdraws
**for declarer's seat** (`claim.seat` is declarer's, `claim.hand` declarer's
cards, and their accept shows as declarer's seat in `claim.accepted`); that
robot declarer never claims or answers. While the claim is pending the state's `claim`
shows the claimer's remaining cards face up to everyone, no card may be
played and no other claim made; `turn` doesn't move.

**A refused claim locks claims until the next card.** Once a claim ends
**without being accepted** — rejected, expired or withdrawn — nobody at the
table may claim (the old claimer, either opponent, a robot: the 409
`"A claim was just refused: play a card first."`) until the next card of
the board is played, whoever plays it. Play has to go on: the claimer can
show the opponents they were wrong, or find they claimed too many. The
state's `claim_locked` is `true` meanwhile, so a client can hide Claim and
Concede.

**Silence means no.** A claim not fully accepted within
`BRIDGE_CLAIM_SECONDS` (default **10**, `config/bridge.php`
`claim_seconds`) **expires**: it is rejected exactly as a reject would
reject it — `claim` back to `null`, a `PlayingUpdated`, play resumes where
it stopped — and the accepts already given count for nothing. The state's
`claim.expires_at` says when. The queued job `App\Jobs\ExpireClaim`, sent
with that delay when the claim is made, does it on time while
`queue:work` runs. Without a worker the claim still expires: every request
on the table's playing — `GET .../playing`, a call, a card, a claim
action, the chat, Start, Next, the heartbeat — first expires a claim whose
`expires_at` has passed (the request then sees `claim: null`,
`claim_locked: true`, and a card is played rather than refused), and so
does `tables:check-away`, every ten seconds, for a table where everyone
just waits. Whichever comes first clears the claim and sends the one
`PlayingUpdated`; the others do nothing, as does any of them if the claim
was accepted, rejected or withdrawn first, or if it is a newer claim with
its own deadline. So a client re-reading the state just after
`expires_at` gets the claim cleared. From `expires_at` on no answer
counts: an answer then gets the 409 `"There is no claim to answer."`. The expiry is nobody's
sign of life and doesn't touch the away rule; the turn clock restarts then
(see `turn_deadline`).

All three answer like `POST /tables/{table}/cards`: the updated state as
`GET /tables/{table}/playing` would return it, and a **409** (`sendError`,
`data: []`) with the reason when the action is refused. **403** for anyone
not seated at this table (checked before validation), **401** for guests,
**404** for an unknown table id. Every accepted action sends
`PlayingUpdated`; a refused one sends nothing and changes nothing. The
`board_table` row is locked for the whole request, like a card.

**`POST /tables/{table}/claim`** — make a claim.

| Field | Rules |
|---|---|
| `tricks` | required integer, 0–13 (422 otherwise); must be at most the tricks still to play: 13 less the **complete** tricks, so a trick in progress counts as remaining. `0` concedes them all |

- **201**, message `"Claim made successfully."`, `claim` now
  `{seat, tricks, hand, accepted: [], expires_at}`.
- **409**:
  - `"You can claim between 0 and 12 tricks: 12 remain to be played."`;
  - `"A claim is already pending: N claims 10."`;
  - `"A claim was just refused: play a card first."` — a claim was
    rejected, expired or withdrawn and no card has been played since
    (`claim_locked` is `true`);
  - `"Dummy takes no part in a claim: declarer claims for declarer's side."`;
  - `"Your partner, dummy, plays declarer's cards and claims for declarer's
    side."` — a robot declarer whose dummy is a human (on all three claim
    actions);
  - `"The auction is not over yet."`, `"The board is finished."`, or
    `"The table has no board yet: ..."` — the phase isn't `play`.

**`POST /tables/{table}/claim/response`** — accept or reject the pending claim.

| Field | Rules |
|---|---|
| `accept` | required boolean |

- **200**. A **reject** clears the claim at once (`claim: null`, message
  `"Claim rejected: play goes on."`, `claim_locked: true`) and play resumes
  where it stopped. An
  **accept** adds the caller's seat to `claim.accepted` (`"Claim accepted."`);
  the last one needed finishes the board (`"Claim accepted: the board is
  finished."`): declarer's `tricks_won` = tricks won so far plus the claimed
  share of the rest — `tricks` if the claimer is on declarer's side, the
  remaining tricks less `tricks` otherwise — scored through
  `BoardTable::finish()` like a 13th trick. The phase is then `finished`,
  `claim` is `null` and `result.claimed` is `true`.
- **409**: `"There is no claim to answer."` (also once the claim has
  expired, `claim.expires_at` reached); `"You made this claim: withdraw
  it instead."`; `"You have already accepted this claim."`; dummy's
  `"Dummy takes no part in a claim: ..."`; or the phase messages above.
- **422** for a missing or non-boolean `accept`.

**`DELETE /tables/{table}/claim`** — the claimer withdraws their pending
claim. No body.

- **200**, message `"Claim withdrawn: play goes on."`, `claim: null`,
  `claim_locked: true`.
- **409**: `"There is no claim to withdraw."`; `"Only the claimer (N) may
  withdraw the claim."`; dummy's or the phase messages above.

A player leaving (or being released as idle) while a claim is pending
detaches the playing as at any other point of the board; the claim goes
with it (and still expires, with no broadcast: the playing is no table's
game any more).

## Boards (results across tables)

A board is played at many tables; these compare what each table made of it
(duplicate bridge, `GAME-RULES.md` §6), and what it held double dummy. All
four endpoints are only for players
who have **finished** the board at some table — a `board_table_seats` row on
one of its playings with `finished_at` set (`BoardPolicy::view`, through
`BoardResultsService::hasFinished()`). Anyone else might still be dealt it,
so they get **403** (Laravel's default `{message}` shape) — including a
player who left mid-board (their playing was detached unfinished), and
admins. **401** for guests, **404** for an unknown board id.

All four read `board_table` and its seat snapshot, which outlive the table: a
playing at a table that has since been deleted is still a result (its
`table_id` is `null`), and still counts as "finished it" for its players.

### `GET /boards/{board}`
The board and all four hands as dealt (from `board_card`). No body.

```json
{
  "status": 200,
  "message": "Board retrieved successfully.",
  "data": {
    "id": 7, "number": 7, "dealer": "S", "vulnerable": "N-S E-W",
    "deal": {"N": [{"id": 52, "suit": "S", "rank": 15, "rank_name": "Ace"}, ...], "E": [...], "S": [...], "W": [...]}
  }
}
```

`deal` is shaped like the game state's `deal`: each hand spades, hearts,
diamonds, clubs, high to low.

### `GET /boards/{board}/results`
Every **finished** playing of the board (played out, claimed or passed out), best
N-S score first (ties in the order they finished). Unfinished ones, at a
table or detached, are left out. Built by
`App\Services\BoardResultsService::results()`.

```json
{
  "status": 200,
  "message": "Results retrieved successfully.",
  "data": {
    "board": {"id": 7, "number": 7, "dealer": "S", "vulnerable": "N-S E-W"},
    "top": 6,
    "results": [
      {
        "playing_id": 42,
        "table_id": 3,
        "players": {"N": {"id": 1, "name": "Ann", "username": "ann", "description": null, "is_robot": false, "is_admin": false}, "E": {...}, "S": {...}, "W": {...}},
        "contract": {"id": 22, "call": "4S", "level": 4, "strain": "S", "special": false},
        "doubled": 0,
        "declarer": "N",
        "tricks_won": 10,
        "score_ns": 620,
        "made_by": 0,
        "claimed": false,
        "matchpoints": {"ns": 5, "ew": 1},
        "finished_at": "2026-09-29T12:00:00.000000Z"
      }
    ]
  }
}
```

| Field | Meaning |
|---|---|
| `board` | `id`, `number`, `dealer`, `vulnerable` |
| `top` | the most matchpoints one result can get: 2 × (number of results − 1); `0` when the board has one result |
| `results[].playing_id` | the `board_table.id` |
| `results[].table_id` | the table it was played at, or `null` once that table has been deleted |
| `results[].players` | seat → public profile (`UserResource`), from the playing's seat snapshot |
| `contract`, `doubled`, `declarer`, `tricks_won`, `score_ns`, `made_by`, `claimed` | exactly the game state's `result` (see [`GET /tables/{table}/playing`](#get-tablestableplaying)); a passed out board has `score_ns: 0` and the rest `null` |
| `results[].matchpoints` | `{ns, ew}`: against every other result on the board, 2 for a better N-S score and 1 for a tie (`ns`); `ew` is `top − ns`. Example: N-S scores 620, 620, 170, −100 → `ns` 5, 5, 2, 0 out of 6. **Not stored**: worked out on every read (`ScoringService::matchpoints()`), since each new playing changes everyone's |
| `results[].finished_at` | when the board ended at that table |

IMPs (teams) aren't built.

### `GET /boards/{board}/double-dummy`
The board's **double dummy table**: how many tricks each declarer makes in
each strain with all four hands in view and both sides playing their best
(`GAME-RULES.md` §6, *Double dummy*). It depends only on the deal, so it is
the same for every table. No body; 403/404/401 as above.

```json
{
  "status": 200,
  "message": "Double dummy analysis retrieved successfully.",
  "data": {
    "status": "ready",
    "table": {
      "N": {"C": 7, "D": 5, "H": 6, "S": 5, "NT": 6},
      "E": {"C": 5, "D": 7, "H": 6, "S": 8, "NT": 6},
      "S": {"C": 7, "D": 5, "H": 6, "S": 5, "NT": 6},
      "W": {"C": 5, "D": 7, "H": 6, "S": 8, "NT": 6}
    }
  }
}
```

| Field | Meaning |
|---|---|
| `status` | `ready`; `pending` while the queued job hasn't solved it yet (poll again, or look again later); `unavailable` when the server has no solver (`DDS_LIBRARY` unset, see [`RUNNING.md`](RUNNING.md#double-dummy-dds)) |
| `table` | declarer's seat → strain (`C`, `D`, `H`, `S`, `NT`) → tricks declarer takes (0–13). `null` unless `status` is `ready` |

The table is solved **once per board, in the queue** (`queue:work`), when
the board is first dealt, and stored; nothing is solved in a request. A
board dealt before this existed has none stored: the first read queues it
and answers `pending`. **Par** (the par contract and score) isn't built.

### `GET /playings/{playing}`
One **finished** playing after the fact — how the board was bid and played —
so a player can review their own board, or see how another table reached a
better contract. `{playing}` is a `board_table.id`: the `playing_id` on each
`GET /boards/{board}/results` row and each history row. No body.

- **200**, message `"Playing retrieved successfully."`, for a player who has
  finished **that playing's board** at some table — the same rule
  (`BoardPolicy::view`) as the two endpoints above, so any finished playing
  of a board you have finished, not only your own.
- **403** (Laravel's default `{message}` shape) for anyone else.
- **404** for an unknown id **and for an unfinished playing**, whether still
  at its table or detached because a player left mid-board — it has no
  result to review. **401** for guests.

`data` is exactly the public game state of
[`GET /tables/{table}/playing`](#get-tablestableplaying)
(`PlayingResource`) for that playing, with `phase: "finished"`, **less
`ready` and `next_board_at`** (which only mean something at a live table) and without the
viewer's `my_seat` / `hand`: `playing_id`, `board`, `players` (from the seat
snapshot), `turn` and `acting_user_id` (both `null`), `auction` (every call
in order), `contract`, `tricks` (the complete tricks in order),
`current_trick`, `tricks_won`, `dummy_hand`, `claim` (`null`), `claim_locked` (`false`), `result` and
`deal` (all four hands as dealt). It works the same once the table has been
deleted.

Each `auction` entry is `{seat, bid, alert}`: once the board is over every
alert is public, so `alert` is the call's `{explanation}` (null explanation:
alerted without one) for whoever reviews it, or null when the call wasn't
alerted. There is no `question`.

`messages` is the board's whole [chat](#chat), oldest first, in the
message shape — every message, `opponents` ones included, since the board
is over. `[]` when nobody wrote.

`double_dummy` is the board's double dummy analysis, for this playing's
contract:

```json
"double_dummy": {
  "status": "ready",
  "table": {"N": {"C": 7, "D": 5, "H": 6, "S": 5, "NT": 6}, "E": {...}, "S": {...}, "W": {...}},
  "leads": [
    {"card": {"id": 50, "suit": "S", "rank": 13, "rank_name": "Queen"}, "tricks": 8},
    {"card": {"id": 49, "suit": "S", "rank": 12, "rank_name": "Jack"}, "tricks": 8},
    ...
  ]
}
```

| Field | Meaning |
|---|---|
| `status` | `ready` once both `table` and `leads` are in; `pending` while either queued job hasn't run; `unavailable` with no solver on the server |
| `table` | as in [`GET /boards/{board}/double-dummy`](#get-boardsboarddouble-dummy); `null` until solved |
| `leads` | every card the opening leader (declarer's left) held, in hand order (spades, hearts, diamonds, clubs, high to low), with `tricks`: what declarer makes after that lead with best play from there on. It depends on declarer and strain only (not the level or doubling), so every table that reached that contract shares it; solved in the queue when the first of them finishes. `null` until solved, and **always `null` on a passed out board**, which is `ready` with its `table` alone |

Compare the lead actually made (`tricks[0].cards[0]`) with the rest:
`leads` with fewer `tricks` were better for the defence.

A board that ended by an accepted claim has only the tricks played up to the
claim (the unfinished one in `current_trick`, dummy's unplayed cards in
`dummy_hand`) and `result.claimed: true`. A passed out board has its four
passes in `auction` and `contract`, `tricks` etc. `null`.

**Playings finished before this endpoint existed** (branch `34-board-review`)
lost their calls and cards when their table was deleted: they come back with
`auction: []` and, when there was a contract, `tricks: []` — the contract,
result and deal are still there.

## Sets

Play at a table goes in **sets** of four boards (`bridge.set_size`, env
`BRIDGE_SET_SIZE`), the same four players in the same seats throughout
(`GAME-RULES.md` §8, "Sets of boards"):

1. everybody presses Start ([`POST /tables/{table}/start`](#post-tablestablestart))
   → the set's first board;
2. after each board its result stays on show for `BRIDGE_NEXT_BOARD_SECONDS`
   (10; the state's `next_board_at`) and then the next board is dealt by
   itself — or at once when every human has asked for it
   ([`POST /tables/{table}/playing/next`](#post-tablestableplayingnext)) →
   boards 2, 3 and 4;
3. after the fourth the set is over: no board comes by itself
   (`next_board_at: null`), Next 409s, the board stays on show,
   the players read the set's results, and everybody's Start opens the next
   set (`number` 2, `board` 1).

A player who **walks out** on a set — lets their turn clock run out
(`turn_timeout`, or `away` if they were away), moves to another table
(`moved`), or is kicked while away or banned (`kicked`) — doesn't end it: a
robot takes their seat and plays on, and the set's `replaced` lists them
(see [Away mid-set](#away-mid-set-and-the-turn-clock)). A Leave mid-set only
holds the seat until the board has waited a turn for them.

A set ends early, even between boards, as `abandoned`, with no winner, when
one of its four is taken out of the table otherwise (kicked while there,
leaving while an admin there is away, an admin leaving), or walks out with
no other human left at the table to play with a robot.

The board in play then goes as before (detached, unscored), and the
refilled table's Start opens a new set.

The game state, `PlayingUpdated` and every table payload carry `set`; the
history rows carry it too. Sets outlive their table (`table_id` goes
`null`), like the playings in them.

### `GET /sets/{set}`
A set's results. No body. Built by `Game\TableSetController@show` over
`BoardResultsService::set()`. Works while the set is going on (with the
boards finished so far) and after its table is deleted.

- **200**, message `"Set retrieved successfully."`, for one of the set's
  four players (left since or not), or anyone who has finished **every**
  board the set finished, at any table (`TableSetPolicy::view`).
- **403** (Laravel's default `{message}` shape) for anyone else, admins
  included: they may still be dealt one of its boards.
- **401** for guests, **404** for an unknown set id.

```json
{
  "status": 200,
  "message": "Set retrieved successfully.",
  "data": {
    "id": 5, "number": 1, "table_id": 3, "of": 4, "boards_dealt": 4,
    "started_at": "...", "finished_at": "...", "finished": true,
    "ended": "completed",
    "players": {"N": {"id": 1, "name": "Ann", ...}, "E": {...}, "S": {...}, "W": {...}},
    "replaced": [],
    "boards": [
      {
        "position": 1, "playing_id": 42,
        "board": {"id": 7, "number": 7, "dealer": "S", "vulnerable": "N-S E-W"},
        "contract": {"id": 22, "call": "4S", ...}, "doubled": 0, "declarer": "N",
        "tricks_won": 10, "score_ns": 620, "made_by": 0, "claimed": false,
        "top": 2, "matchpoints": {"ns": 2, "ew": 0}
      }
    ],
    "totals": {"score": {"ns": 1150, "ew": -1150}, "matchpoints": {"ns": 2, "ew": 0}, "top": 2},
    "winner": "NS"
  }
}
```

| Field | Meaning |
|---|---|
| `number`, `of`, `finished`, `ended`, `replaced` | as the game state's `set` |
| `table_id` | `null` once the table has been deleted |
| `boards_dealt` | how many of its boards were dealt, including one abandoned mid-play (not listed in `boards`) |
| `players` | seat → public profile (`UserResource`), the four who play the set's seats: a robot where it took a seat over (`replaced` names whom from). A player a robot replaced may still read the set's results |
| `boards` | the set's **finished** boards, in order: `position` (1–`of`), `playing_id` (reviewable with `GET /playings/{playing}`), `board`, the game state's `result` fields, and `top` and `matchpoints` against **every** finished playing of that board at any table, worked out now as in `GET /boards/{board}/results` (a board only this table has played has `top: 0`) |
| `totals` | `score`: the boards' `score_ns` added up, and the same from E-W's side; `matchpoints`: each side's matchpoints added up, out of `top` |
| `winner` | `NS` or `EW`, the side with the higher total **score**; `null` while the set goes on, on a tie, and for an `abandoned` set. A side a robot finished the set for can win it too. Matchpoints don't decide it |

## Users

A user's profile has two views:

- **Public** (`UserResource`): `id`, `name`, `username`, `description`,
  `is_robot` (true for a robot player, see [Tables](#tables)) and
  `is_admin` (true for an admin, whose seat only another admin may take
  away — see `DELETE /tables/{table}/seats/{user}`). This is
  what other players see — in `GET /users/{user}`, in `GET /users?search=`
  (which adds `seated`) and nested in results and history. Table payloads
  (a seat's `user`) and the game state (`players`) nest it **without
  `description`** (`PlayerResource`), to keep their broadcasts under
  [10 KB](#message-size).
- **Own**: the full serialised `User` (adds `email`, `email_verified_at`,
  timestamps; `password` and `remember_token` stay hidden) plus `is_admin`
  (boolean, read-only) and `ban`: the [ban](#bans) keeping them away from
  the game, `{reason, until, banned_at}`, or `null`. Only the user
  themselves gets it, from
  `GET /api/user` and `PATCH /api/user`. A client doesn't need `is_admin` to
  decide what a player may do at a table — use the table's `can_manage` —
  except that an admin's seat has no **Remove** for anyone but another
  admin.

### `GET /users?search=`
Look users up by text, e.g. for a table manager picking someone to seat with
`POST /tables/{table}/seats/users`. Requires a logged-in session (**401** for
guests).

| Param | Rules |
|---|---|
| `search` | **required**, string, 2–255 characters (**422** otherwise) |

- Matches users whose `username` **or** `name` contains `search`,
  case-insensitively. `%` and `_` are matched literally, not as wildcards.
- Never returns **robots** (`is_robot`): they are seated with
  `POST /tables/{table}/seats/robots`, not picked by name.
- Never matches on `email` and never returns it, so it can't be used to check
  whether an address has an account.
- **200** with at most **10** public profiles, ordered by `username` (there is
  no paging — type more to narrow it down). Each adds `seated`: `true` if the
  user holds a seat at any table, so seating them would 409. The caller can
  appear in their own results.
- Throttled to 30 requests a minute per user (**429** beyond that), so debounce
  the input.

```json
{
  "status": 200,
  "message": "Users retrieved successfully.",
  "data": [
    {"id": 3, "name": "Ann", "username": "ann", "description": "Plays a strong club.", "is_robot": false, "is_admin": false, "seated": false},
    {"id": 7, "name": "Joanna", "username": "jo", "description": null, "is_robot": false, "is_admin": false, "seated": true}
  ]
}
```

### `GET /users/{user}`
Another user's public profile. `{user}` is a `users.id`; **404** if it
doesn't exist. Requires a logged-in session (**401** for guests).

```json
{
  "status": 200,
  "message": "User retrieved successfully.",
  "data": {"id": 3, "name": "Ann", "username": "ann", "description": "Plays a strong club.", "is_robot": false, "is_admin": false}
}
```

For an **admin** the profile adds `ban`, the ban in force (`null` when
there is none), and `bans`, every ban the user has had, latest first —
both in the admin shape of [`POST /users/{user}/ban`](#post-usersuserban).
Nobody else ever gets either key, not even the user themselves (they have
`ban` on `GET /api/user`, without who gave it).

### Bans

An admin keeps a user who cheats, plays several accounts at once or
colludes away from the game for a number of days. While banned the user
may still log in and read their own record (`GET /api/user`'s `ban`), but
every game action is refused with a 403 naming the end date and the reason
(see the top of this file), and a table manager can't seat them (409
`"That user is banned."`). The ban ends by itself at `until`; nothing has
to run. Bans are kept as history (`user_bans`, see
[`DATA-MODEL.md`](DATA-MODEL.md#userban-user_bans)).

#### `POST /users/{user}/ban`
Admins only.

| Field | Rules |
|---|---|
| `days` | **required**, integer, 1–365: the ban ends this many days from now |
| `reason` | **required**, string, max 500 characters (`UserBan::REASON_MAX`; `UserBanned` carries it, see [Message size](#message-size)): shown to the banned user |

At once, in one transaction:
- the user's seat, if they hold one, is freed as if they had walked out
  (moderation is handed on, an emptied table is deleted, the others get
  `TableUpdated`). In the middle of a [set](#sets) a **robot takes their
  seat** at once, with no turn clock to wait for (`replaced` reason
  `kicked`; unless an admin at the table is away or nobody else human is
  left, when the set is abandoned, as for any leave);
- their sessions are deleted and their remember-me token replaced, so their
  next request is a **401** (see [`AUTH.md`](AUTH.md#bans));
- [`UserBanned`](#event-userbanned) goes to their own channel, so an open
  client can log them out and show the reason.

A ban already in force is replaced: it is closed (`lifted_at`, `lifted_by`)
and the new one starts now, shorter or longer.

- **201**, message `"User banned until 12 Oct 2026."`, plus
  `" They were in the middle of a set, so a robot took their seat."` when
  one did:

```json
{
  "status": 201,
  "message": "User banned until 12 Oct 2026.",
  "data": {
    "id": 4,
    "user_id": 9,
    "reason": "Playing two accounts at once.",
    "banned_at": "2026-10-05T18:30:00.000000Z",
    "until": "2026-10-12T18:30:00.000000Z",
    "banned_by": {"id": 1, "name": "Admin", "username": "admin", "description": null, "is_robot": false, "is_admin": true},
    "lifted_at": null,
    "lifted_by": null,
    "active": true
  }
}
```

- **403** (`{message}`) for a non-admin (`"Only an admin can ban a user."`),
  and for an admin aiming at themselves (`"You cannot ban yourself."`),
  another admin (`"An admin cannot be banned."`) or a robot
  (`"A robot cannot be banned."`) — checked before validation.
- **422** (default Laravel shape) for a missing `reason` or `days` out of range.
- **404** for an unknown user, **401** for guests.

#### `DELETE /users/{user}/ban`
Admins only (**403** `{message}` otherwise). Lifts the ban in force at once:
**200**, `"Ban lifted."`, with the ban in the same shape (`lifted_at`,
`lifted_by` set, `active: false`). **404** `"That user is not banned."`
(envelope) when there is none. Their sessions are already gone, so the
user just logs in again.

### `GET /users/{user}/playings` and `GET /api/user/playings`
A user's **finished** playings (played out, claimed or passed out), latest first,
20 per page (`?page=2` for the next). `/users/{user}/playings` is anyone's
(any logged-in user may read it; **404** for an unknown user id);
`/api/user/playings` (in `routes/api.php`, `auth:sanctum`) is the caller's
own, the same list. **401** for guests. Built by
`App\Services\BoardResultsService::history()`, from `board_table_seats`, so
it survives the table being deleted. A playing its player left before the end
isn't in it. Rows carry no cards; the deal is behind `GET /boards/{board}`,
and each row's auction and play behind `GET /playings/{playing_id}`.

`data` is Laravel's paginator:

```json
{
  "status": 200,
  "message": "Playings retrieved successfully.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "playing_id": 42,
        "table_id": null,
        "set": {"id": 5, "number": 1, "board": 2, "of": 4},
        "board": {"id": 7, "number": 7, "dealer": "S", "vulnerable": "N-S E-W"},
        "seat": "E",
        "partner": {"id": 4, "name": "Di", "username": "di", "description": null},
        "contract": {"id": 22, "call": "4S", "level": 4, "strain": "S", "special": false},
        "doubled": 0,
        "declarer": "N",
        "tricks_won": 10,
        "score_ns": 620,
        "made_by": 0,
        "claimed": false,
        "score": -620,
        "finished_at": "2026-09-29T12:00:00.000000Z"
      }
    ],
    "first_page_url": "...", "from": 1, "last_page": 1, "last_page_url": "...",
    "links": [...], "next_page_url": null, "path": "...", "per_page": 20,
    "prev_page_url": null, "to": 1, "total": 1
  }
}
```

| Field | Meaning |
|---|---|
| `table_id` | `null` once the table has been deleted |
| `set` | the [set](#sets) the board was dealt in — `id`, `number`, `board` (its place in the set), `of` — to group the history by set; its results are `GET /sets/{id}`. `null` only for a playing made outside the game services |
| `board` | `id`, `number`, `dealer`, `vulnerable` |
| `seat` | the seat the user held, from the snapshot |
| `partner` | the player in the opposite seat (`UserResource`) |
| `contract`, `doubled`, `declarer`, `tricks_won`, `score_ns`, `made_by`, `claimed` | the game state's `result` |
| `score` | the same score from the **user's** side: `score_ns` for N/S, negated for E/W |

Matchpoints aren't in the history; read them from
`GET /boards/{board}/results`.

### `GET /users/{user}/stats` and `GET /api/user/stats`
How a user plays: their boards, their sets and the sets they walked out on.
`/users/{user}/stats` is anyone's (any logged-in user may read it, a robot's
included — a client may hide it for robots; **404** for an unknown user
id); `/api/user/stats` (in `routes/api.php`, `auth:sanctum`) is the
caller's own. **401** for guests. Built by
`App\Services\PlayerStatsService::stats()` from `board_table_seats`,
`table_set_seats` and `table_sets`, so tables being deleted loses nothing.
Matchpoints change as more tables finish a board, so the stats are worked
out on **every read** and never stored; that is also why they aren't part
of `GET /users/{user}`. Admins are counted like anyone.

```json
{
  "status": 200,
  "message": "Stats retrieved successfully.",
  "data": {
    "user_id": 3,
    "boards": {"played": 5, "compared": 4, "won": 2, "win_rate": 0.5, "average_percent": 56.25},
    "sets": {"played": 3, "won": 1, "win_rate": 0.3333, "average_percent": 62.5},
    "leaving": {
      "abandoned": 1,
      "abandoned_by_reason": {"turn_timeout": 1, "away": 0, "moved": 0, "kicked": 0, "left": 0},
      "left_rate": 0.25
    }
  }
}
```

| Field | Meaning |
|---|---|
| `boards.played` | finished boards the user played in their own seat, passed out included (a board whose hand a robot took over is the robot's) |
| `boards.compared` | those with matchpoints: at least one other table has finished the same board |
| `boards.won` | compared boards where the user's side got **more than 50 %** of the matchpoints |
| `boards.win_rate` | `won / compared` (0–1, 4 decimals) |
| `boards.average_percent` | the side's mean matchpoint percentage (0–100, 2 decimals) over the compared boards |
| `sets.played` | sets that ended `completed` with the user still in their seat — not one a robot finished for them |
| `sets.won` | those whose `winner` (as in `GET /sets/{set}`: the higher total score) is the user's side |
| `sets.win_rate` | `won / played` |
| `sets.average_percent` | the mean, over those sets with any board another table has played, of the side's matchpoints as a percentage of the set's top |
| `leaving.abandoned` | sets the user walked out on: each set where a robot took their seat (`table_set_seats.replaced_user_id`, whatever became of the set), plus each set that ended `abandoned` as they left (`table_sets.ended_by`: no robot stepped in — an admin's own Leave, while an admin was away, or with no other human left). Never counted against the partner they left |
| `leaving.abandoned_by_reason` | the same by why: `turn_timeout`, `away`, `moved`, `kicked` (the robot's `replaced_reason`) and `left` (the set ended `abandoned`) |
| `leaving.left_rate` | `abandoned / (sets.played + abandoned)` |

A rate or percentage with nothing to divide by is `null`; whole numbers come
as JSON integers (`1`, `100`). A set kicked out of shape by a manager
removing a player **who was there** ends `abandoned` with nobody to blame
(`ended_by` null), so it counts against nobody. Sets that ended `abandoned`
before `ended_by` existed aren't attributed to anyone.

### `PATCH /api/user`
The logged-in user edits their **own** profile (in `routes/api.php`, behind
`auth:sanctum`, next to `GET /api/user`). There is no user id in the URL, so
nobody can edit anyone else's profile.

| Field | Rules |
|---|---|
| `name` | optional; if present, a non-empty string, max 50 characters (`User::NAME_MAX`, see [Message size](#message-size)) |
| `description` | optional; nullable string, max 1000 (`null` clears it) |

- Any other field (`username`, `email`, `is_admin`, `id`, `password`, ...) is
  **ignored**, not rejected: username, email and password can't be changed
  through the API yet, and `is_admin` never can be.
- **200** with the caller's own full record (same shape as `GET /api/user`,
  email and `is_admin` included), message `"Profile updated successfully."`
- **422** (default Laravel shape) for an invalid `name` / `description`.
- **401** for guests.

## Realtime (websocket)

Implemented on `16-realtime-transport`, over Laravel Reverb (why, and how to
run it: [`RUNNING.md`](RUNNING.md#realtime-reverb); how a client subscribes:
[`AUTH.md`](AUTH.md#websocket-channels-reverb)). Reverb speaks the Pusher
protocol, so any Pusher client (`pusher-js`, Laravel Echo) works.

### Channels

| Channel (as the client names it) | Echo | Who may subscribe | Carries |
|---|---|---|---|
| `private-table.{id}` | `echo.private('table.' + id)` | players seated at table `{id}` (`routes/channels.php`) | `TableUpdated`, `PlayingUpdated` |
| `private-App.Models.User.{id}` | `echo.private('App.Models.User.' + id)` | user `{id}` only | `HandDealt` — one player's own cards; `DeclarerHandShown` — a robot declarer's cards, for its human dummy; `CallAlerted` — an opponent's alert or answer (anyone's, in the play); `AuctionAlertsShown` — partner's alerts, when the auction ends; `CallQuestioned` — a question about your call; `BoardMessageSent` — a chat message you may read; `UserBanned` |

A client should subscribe to its table's channel **after** it has a seat
(the subscription is refused otherwise), and re-subscribe after moving to
another table. Leaving doesn't end the subscription server-side; the client
should unsubscribe (`echo.leave('table.' + id)`).

### Message size

Every event fits in **10 KB**, hosted Pusher's limit on one event, so moving
from Reverb to Pusher stays a `.env` change. An event that doesn't fit is
refused (`Pusher error: Payload too large.`), its queued job fails, and
nobody sees that state until they reload. What is measured is the body the
app POSTs: event name, channels, and the data as a JSON **string** inside
the JSON, so every quote in it costs two bytes and a non-ASCII character up
to 14 (an emoji, `😀`, escaped once more). What keeps each event
under it, with room left for the HTTP request around it
(`App\Broadcasting\PusherBody::BUDGET`, 9,000 bytes):

- `PlayingUpdated` sends cards and bids as ids (its [compact
  shape](#event-playingupdated));
- a seat's `user` and the game state's `players` leave out `description`
  (open `GET /users/{user}` for it);
- the free text broadcasts carry has a length limit, in characters:
  `name` 50 and `username` 30 (`/register`, `PATCH /api/user`), a table's
  `name` 50 (`POST /tables`), a ban's `reason` 500, a call's
  `explanation` 200, a chat message's `body` 500.

`tests/Feature/Game/BroadcastSizeTest` builds the largest payload of every
event — the longest legal auction (319 calls), all 13 tricks and the whole
deal, a pending 13-card claim, every name at its limit in emoji — and checks
it against the budget. A broadcast that fails all the same is logged at
`error` with its event, channels, table, user and size
(`App\Listeners\LogFailedBroadcast`).

### Event `TableUpdated`

Event name on the wire: `App\Events\TableUpdated` (Echo:
`.listen('TableUpdated', ...)`). Class `App\Events\TableUpdated`, on
`private-table.{id}`.

**Sent when** a seat at that table changes — every path goes through
`TableSeatService` or `BoardSelectionService`:
- a player takes a seat (`POST /tables`, `POST /tables/{table}/seats`), or a
  manager seats someone (`POST /tables/{table}/seats/users`) or a robot
  (`POST /tables/{table}/seats/robots`, and each robot `POST /tables` seats
  with `robots: true`);
- a player changes seat at the same table;
- a player leaves or is kicked (`DELETE /tables/{table}/seats`,
  `DELETE /tables/{table}/seats/{user}`), which may also hand the moderator
  role on, or leave the table unattended (`unattended_since` set);
- a player **moves** to another table: one event for the table they left and
  one for the table they joined;
- a player presses Start or takes it back (`POST`/`DELETE
  /tables/{table}/start`), changing their seat's `ready`;
- mid-set, a player is marked **away** (`tables:check-away`, or their
  Leave) or comes **back** (a heartbeat or playing request), changing their
  seat's `away_since`; and a **robot takes the seat** of a player who
  walked out on the set (their turn clock ran out, a move, a kick while
  away, a ban: the seat's new `user`, `set.replaced`) — see
  [Away mid-set](#away-mid-set-and-the-turn-clock). The turn clock moving
  from player to player is in `PlayingUpdated` (`turn_deadline`), not
  here;
- a board is dealt — by the last Start, by a robot taking the fourth seat
  after every human has pressed it, or by the last `playing/next`: that
  event is the first with a non-null `board_id` (and the humans' `ready`
  cleared); a player leaving mid-board sends it back to null. Its `set`
  moves with it: a new set on a Start, the next `board` on a Next, and
  `finished` when a player leaving abandons the set.

**Not sent** when the change deleted the table (the last player left, or
`tables:delete-unattended` removed an unattended one) — nobody is left to
receive it — nor for a request that failed (409/404/403/422).
Events fire only once the database transaction commits, so a move that rolls
back announces nothing.

**Payload** — `{table}`, the `data` of the HTTP table responses
(`TableResource`: public player profiles, no emails) **without
`can_manage`**. That field is the caller's own answer, and a broadcast has no
single caller (it would otherwise carry whoever made the change's value), so
it is left out rather than sent wrong. Keep the last `can_manage` you got over
HTTP, and refetch `GET /tables/{table}` when an event's `moderated_by` differs
from the one you had — the only way it changes for a player who didn't make
the request (the one who did gets it in their HTTP response).

```json
{
  "table": {
    "id": 7,
    "name": "Demo table",
    "created_by": 12,
    "moderated_by": 12,
    "board_id": null,
    "unattended_since": null,
    "created_at": "2026-09-22T10:15:02.000000Z",
    "updated_at": "2026-09-22T10:15:02.000000Z",
    "seats": [
      {
        "id": 21, "table_id": 7, "user_id": 12, "seat": "N",
        "last_seen_at": "...", "ready_at": "2026-09-22T10:16:40.000000Z", "away_since": null,
        "created_at": "...", "updated_at": "...", "ready": true,
        "user": {"id": 12, "name": "Alice", "username": "alice", "is_robot": false, "is_admin": false}
      },
      {
        "id": 22, "table_id": 7, "user_id": 13, "seat": "E",
        "last_seen_at": "...", "ready_at": null, "away_since": null,
        "created_at": "...", "updated_at": "...", "ready": false,
        "user": {"id": 13, "name": "Bob", "username": "bob", "is_robot": false, "is_admin": false}
      }
    ],
    "free_seats": ["S", "W"],
    "set": null
  }
}
```

It is a snapshot of the table as that change left it, taken when the event
fires rather than when the queue worker sends it, so a late event still shows
its own state. Treat each one as the whole table — replace, don't merge; with
one queue worker they arrive in the order they happened. The player whose request caused the change
gets the event too, as well as the HTTP response.

### Event `PlayingUpdated`

Class `App\Events\PlayingUpdated`, on `private-table.{id}` (Echo:
`.listen('PlayingUpdated', ...)`). Same delivery as `TableUpdated`: queued,
sent only once the transaction commits, payload snapshotted at dispatch.

**Sent when**
- a board is dealt (the last Start, or a robot taking the fourth seat once
  every human has pressed it) — in the same request as the `TableUpdated`
  that first carries a non-null `board_id`;
- a call is accepted (`POST /tables/{table}/calls`), including the one that
  ends the auction;
- a card is played (`POST /tables/{table}/cards`), including the last one;
- a claim is made, accepted, rejected or withdrawn (the three
  `/tables/{table}/claim` endpoints), including the accept that finishes the
  board;
- a player asks for the next board (`POST /tables/{table}/playing/next`) —
  `ready` changes — and again when the last human deals it, or when the
  timer (`DealNextBoard`, at `next_board_at`) does;
- a **robot** does any of the above: its moves go through the same services,
  so each one sends `PlayingUpdated` exactly like a human's. Robot moves come
  about a second apart, so a board with robots streams one event per move.

It is **not** sent when a player leaving abandons the board; that shows up as
`TableUpdated` with `board_id` back to null.

**Payload** — `{playing}`, the **public** part of
`GET /tables/{table}/playing`: `phase`, `playing_id`, `set`, `board`, `players`,
`turn`, `acting_user_id`, `auction`, `contract`, `tricks`, `current_trick`,
`tricks_won`, `dummy_hand`, `claim`, `claim_locked`, `result`, `deal`, `ready`, `next_board_at`. It never carries `hand`, `declarer_hand` or `my_seat`: the table
channel is only authorised at subscribe time, so a player who has left may
still be listening. The only cards in it are face up — the ones played,
after the opening lead dummy's, the claimer's while a claim is pending, and
once the board is finished the whole deal.

It comes in a **compact shape** (`PlayingResource::compact()`), not the
HTTP one: a finished board written out card object by card object is ~18 KB,
well over a broadcast's [10 KB](#message-size). The keys are the same, and
everything not listed here is exactly as over HTTP; what grows with the
board is written as ids, which a client looks up in
[`GET /cards`](#implemented--routed) and [`GET /bids`](#get-bids) (fetch both once):

| Field | Over HTTP | In `PlayingUpdated` |
|---|---|---|
| `auction` | `[{seat, bid}]` | `[bid id]`, in order: the first call is `board.dealer`'s, each next one the next seat clockwise |
| `contract.bid` | bid object | bid id |
| `tricks` | `[{round, leader, cards: [{seat, card}], winner}]` | `[{leader, cards: [card id], winner}]`: `round` is the trick's place in the list (from 1), and `cards` go clockwise from `leader` |
| `current_trick` | `[{seat, card}]` | `{leader, cards: [card id]}`, clockwise from `leader`; `{leader: null, cards: []}` when no card of it is played yet |
| `dummy_hand`, `claim.hand` | `[card]` | `[card id]`, in the same order |
| `result.contract` | bid object or null | bid id or null |
| `deal` | `{seat: [card]}` | `{seat: [card id]}`, in the same order |

Each is `null` exactly when it is over HTTP. Expanding them back gives the
HTTP state exactly (`tests/Feature/Game/BroadcastSizeTest` checks it), so a
client can expand each event into the shape it already reads and treat it
as before:

```js
const expand = (p, cardsById, bidsById) => {
  const plays = (leader, ids) => ids.map((id, i) => ({ seat: clockwise(leader, i), card: cardsById[id] }));
  const hand = (ids) => ids && ids.map((id) => cardsById[id]);
  return {
    ...p,
    auction: p.auction && p.auction.map((id, i) => ({ seat: clockwise(p.board.dealer, i), bid: bidsById[id] })),
    contract: p.contract && { ...p.contract, bid: bidsById[p.contract.bid] },
    tricks: p.tricks && p.tricks.map((t, i) => ({ round: i + 1, leader: t.leader, cards: plays(t.leader, t.cards), winner: t.winner })),
    current_trick: p.current_trick && plays(p.current_trick.leader, p.current_trick.cards),
    dummy_hand: hand(p.dummy_hand),
    claim: p.claim && { ...p.claim, hand: hand(p.claim.hand) },
    result: p.result && { ...p.result, contract: p.result.contract === null ? null : bidsById[p.result.contract] },
    deal: p.deal && Object.fromEntries(Object.entries(p.deal).map(([seat, ids]) => [seat, hand(ids)])),
  };
};
// clockwise('N', 0) === 'N', clockwise('N', 1) === 'E', ..., clockwise('W', 1) === 'N'
```

A `GET /cards` row is exactly the state's card, `{id, suit, rank,
rank_name}`, and a `GET /bids` entry exactly its bid.

```json
{
  "playing": {
    "phase": "play",
    "playing_id": 42,
    "set": {"id": 5, "number": 1, "board": 2, "of": 4, "finished": false, "ended": null, "replaced": []},
    "board": {"id": 7, "number": 7, "dealer": "S", "vulnerable": "N-S E-W"},
    "players": {"N": {"id": 1, "name": "Ann", "username": "ann", "is_robot": false, "is_admin": false}, "E": {...}, "S": {...}, "W": {...}},
    "turn": "N",
    "acting_user_id": 3,
    "turn_deadline": "2026-09-22T10:20:31.000000Z",
    "auction": [21, 1, 1, 1],
    "contract": {"bid": 21, "doubled": 0, "declarer": "S", "dummy": "N"},
    "tricks": [{"leader": "W", "cards": [33, 9, 45, 27], "winner": "E"}],
    "current_trick": {"leader": "E", "cards": [8, 6, 12]},
    "tricks_won": {"ns": 0, "ew": 1},
    "dummy_hand": [52, 50, 49, 40, 31, 30, 18, 17, 14, 5, 3, 2],
    "claim": null,
    "claim_locked": false,
    "result": null,
    "deal": null,
    "ready": null,
    "next_board_at": null
  }
}
```

### Event `HandDealt`

Class `App\Events\HandDealt`, on `private-App.Models.User.{id}` (Echo:
`echo.private('App.Models.User.' + myId).listen('HandDealt', ...)`). Same
delivery as `TableUpdated`.

**Sent when** a board is dealt (by the last Start, or the next one after
`POST /tables/{table}/playing/next`): one event to **each** human of the four
players, on their own channel, carrying only their own cards. Robots get none:
nobody listens on their channel, and a robot reads its hand from the state
when it moves. A client can render its
hand from this without polling `GET /tables/{table}/playing`.

```json
{
  "table_id": 7,
  "playing_id": 42,
  "my_seat": "E",
  "hand": [{"id": 52, "suit": "S", "rank": 15, "rank_name": "Ace"}, ...]
}
```

`hand` has the same order and card shape as in `GET /tables/{table}/playing`.

### Event `DeclarerHandShown`

Class `App\Events\DeclarerHandShown`, on `private-App.Models.User.{id}`
(Echo: `echo.private('App.Models.User.' + myId).listen('DeclarerHandShown', ...)`).
Same delivery as `TableUpdated`.

**Sent when** an auction ends with a **robot declarer whose dummy is a
human**: one event, to that human, who plays both hands (see
`acting_user_id` and `declarer_hand` in
[`GET /tables/{table}/playing`](#get-tablestableplaying)). It carries
declarer's 13 cards, the state's `declarer_hand` at that moment. The auction
usually ends on a robot's call, made in the queue, so no HTTP answer of the
human's has them yet; this is how the client learns them without a
`GET /tables/{table}/playing`. Nothing is sent for any other auction, nor to
anyone else — never on the table channel.

```json
{
  "table_id": 7,
  "playing_id": 42,
  "my_seat": "S",
  "declarer": "N",
  "declarer_hand": [{"id": 52, "suit": "S", "rank": 15, "rank_name": "Ace"}, ...]
}
```

`my_seat` is the human's own seat (dummy), `declarer` declarer's seat, and
`declarer_hand` has the same order and card shape as `hand`. The client then
keeps it like its own hand, dropping each card played from declarer's seat
(or re-reads `declarer_hand` from any game state it is answered).

### Event `CallAlerted`

Class `App\Events\CallAlerted`, on `private-App.Models.User.{id}` (Echo:
`echo.private('App.Models.User.' + myId).listen('CallAlerted', ...)`). Same
delivery as `TableUpdated`.

**Sent when** a call is alerted (`POST /tables/{table}/calls` with `alert`
or an `explanation`, or a robot's conventional call) or its bidder explains
it (`PUT .../explanation`, or a robot answering a question): one event to
each **human opponent of the bidder** during the auction — never to partner,
never on the table channel. Once the auction is over (an answer given during
the play) it goes to each of the **four** humans, the bidder and partner
included.

```json
{
  "table_id": 7,
  "playing_id": 42,
  "index": 2,
  "explanation": "Stayman: 8–17 HCP, asks for a four-card major"
}
```

`index` is the call's place in `auction` (from 0); `explanation` is null for
an alert with no description. The client sets that call's `alert` to
`{explanation}` (and its `question` to null), or re-reads the state.

### Event `AuctionAlertsShown`

Class `App\Events\AuctionAlertsShown`, on `private-App.Models.User.{id}`
(Echo: `echo.private('App.Models.User.' + myId).listen('AuctionAlertsShown',
...)`). Same delivery as `TableUpdated`.

**Sent when** the auction ends (with a contract, or passed out): to each
**human** whose partner alerted at least one call, with those alerts — the
ones they couldn't see during the auction. Nothing is sent to a player whose
partner alerted nothing, and the opponents get none (they had each alert as
it was made, through `CallAlerted`). The auction usually ends on a robot's
call, made in the queue, so no HTTP answer of the human's has them yet.

```json
{
  "table_id": 7,
  "playing_id": 42,
  "alerts": [
    {"index": 2, "explanation": "Stayman: 8–17 HCP, asks for a four-card major"},
    {"index": 6, "explanation": null}
  ]
}
```

`alerts` is in auction order: `index` the call's place in `auction` (from
0), `explanation` null for an alert with no description. The client sets
each of those calls' `alert` to `{explanation}`, or re-reads the state. A
very long auction may hold more alerts than one broadcast carries (10 KB):
they then come in **several** `AuctionAlertsShown` events, each with the
next ones in order — merge them by `index`.

### Event `CallQuestioned`

Class `App\Events\CallQuestioned`, on `private-App.Models.User.{id}`. Same
delivery as `TableUpdated`.

**Sent when** an opponent asks about a human's call
(`POST /tables/{table}/calls/{index}/question`): one event, to that call's
bidder, who answers with `PUT /tables/{table}/calls/{index}/explanation`. A
robot bidder answers at once and gets none.

```json
{"table_id": 7, "playing_id": 42, "index": 0, "asked_by": "W"}
```

### Event `BoardMessageSent`

Class `App\Events\BoardMessageSent`, on `private-App.Models.User.{id}`
(Echo: `echo.private('App.Models.User.' + myId).listen('BoardMessageSent',
...)`). Same delivery as `TableUpdated`.

**Sent when** a [chat](#chat) message is written — sent with
`POST /tables/{table}/messages`, a robot's answer, or a question or answer
through the [Alerts](#alerts) endpoints: one event to each **human seated
at the table who may read it**, the sender included (so their other tabs
see it). A `table` message reaches all four humans in every phase; an
`opponents` message during the board never reaches the sender's partner,
and no message ever goes on the table channel.

```json
{
  "table_id": 7,
  "playing_id": 42,
  "message": {
    "id": 13,
    "seat": "S",
    "user_id": 3,
    "to": "opponents",
    "call_index": 2,
    "card_index": null,
    "body": "Transfer: 0–17 HCP, 5+ ♠, asks partner to bid ♠",
    "created_at": "2026-10-05T12:00:01.000000Z"
  }
}
```

The client appends `message` to the chat of `playing_id`. When the board
becomes `finished`, partner's earlier messages aren't pushed again: re-read
`GET /tables/{table}/messages` then, which has them all.

Calls, cards and claims re-send `PlayingUpdated` rather than add new table events.
A played card needs no per-player event: it only takes a card out of one
hand, which that player's client can drop itself (or re-read from
`GET /tables/{table}/playing`).

### Event `UserBanned`

Class `App\Events\UserBanned`, on `private-App.Models.User.{id}` (Echo:
`echo.private('App.Models.User.' + myId).listen('UserBanned', ...)`). Same
delivery as `TableUpdated`.

**Sent when** an admin bans the user
([`POST /users/{user}/ban`](#post-usersuserban)). Their sessions are already
gone, so the client should log out at once and show the reason; logging in
again works, and `GET /api/user` carries the same `ban`.

```json
{
  "reason": "Playing two accounts at once.",
  "until": "2026-10-12T18:30:00.000000Z",
  "banned_at": "2026-10-05T18:30:00.000000Z"
}
```

A banned user is refused `private-table.{id}` subscriptions (403); their own
channel stays open.

## Stubs and not built

- No endpoint yet for: renaming or transferring a table by hand, or
  assigning a board to a table by hand (one is dealt automatically once the
  table is full and everyone has pressed Start, and after each finished
  board once the players move on).
- Kicking a player doesn't stop them rejoining: only an admin's
  [ban](#bans) does.
- Robots bid a SAYC-style system and play by rules of thumb
  ([`ROBOTS.md`](ROBOTS.md)); better card play is planned as a separate
  issue. Robots never redouble or claim. Robots don't chat, beyond
  answering a question about one of their calls or, as defenders, their
  cards.
- No presence channel ("who is online"): idle players are detected by the
  heartbeat only.

## Practical implication for a frontend right now

You can currently only:
1. Register/login/logout (session-based, with an optional `remember` on
   login to stay logged in past the session; see [`AUTH.md`](AUTH.md))
2. List/show cards, and list the 38 bids with their ids (`GET /bids`)
3. List tables, show one table with its free seats, and create a table
   (the creator is seated, up to 3 active tables per creator)
4. Join a free seat at another table, and leave your seat — which deletes the
   table if you were the last player, or hands it to the human seated
   there longest if you were the moderator
5. As a table's manager (its moderator, or an admin), find a user by username or name (`GET /users?search=`) and seat
   them at it, or kick a player out of it
6. Read any player's public profile, and edit your own name and description;
   as an admin, ban a user for some days (`POST /users/{user}/ban`), which
   takes them off their table and logs them out, or lift the ban
7. Subscribe to your table's websocket channel and get every seat change
   (and the board being dealt) pushed as `TableUpdated`, instead of polling
8. Press Start (`POST /tables/{table}/start`) and see who else has (each
   seat's `ready`); once four players sit there and every human has
   pressed it, read the dealt board with
   `GET /tables/{table}/playing` — board number, dealer, vulnerability, the
   four players, whose turn it is and your own 13 cards — and get the same
   pushed as `PlayingUpdated` (table channel) and `HandDealt` (your own
   channel)
9. Bid with `POST /tables/{table}/calls`, in turn, with illegal calls refused
   (409 with the reason), until the auction ends with a contract, declarer and
   dummy — or is passed out. Every call is pushed as `PlayingUpdated`. Alert
   your conventional calls to the opponents, ask them about theirs, and
   answer their questions ([Alerts](#alerts)); robots alert and answer too.
   Talk to the whole table, or to the opponents only, at any time in the
   board's [chat](#chat) (`GET`/`POST /tables/{table}/messages`, pushed as
   `BoardMessageSent`); a question about a robot's call, or a robot
   defender's card, gets its answer at once.
10. Play the 13 tricks with `POST /tables/{table}/cards` — declarer plays
    dummy's cards, dummy is face up after the opening lead, follow suit is
    enforced and each trick's winner leads the next. Every card is pushed as
    `PlayingUpdated`; after the last trick the board is `finished` with
    declarer's tricks and its duplicate score (`result`) saved. Or cut the
    play short: claim or concede the remaining tricks
    (`POST /tables/{table}/claim`), which the other non-dummy players accept
    or reject (`POST /tables/{table}/claim/response`); an accepted claim
    scores the board the same way.
11. Get the next board of the set by itself a few seconds after each one
    ends (`next_board_at`), or at once once every human has asked with
    `POST /tables/{table}/playing/next`, in sets of four: after a set's
    fourth board read its results
    (`GET /sets/{set}`, the state's `set.id`) and press Start again for the
    next set.
12. Keep your seat with `POST /tables/{table}/heartbeat` every ~30 s: a
    player who goes quiet (closed tab, lost connection) has their seat freed
    after 5 minutes, or 15 during a board.
13. Play alone against robots: `POST /tables` with `robots: true` fills the
    table with robots, and your one Start deals a board (robots are always
    ready), or a manager fills any free seat with
    `POST /tables/{table}/seats/robots`. The robots bid, play, answer claims
    and move on to the next board by themselves while a human is seated.
14. Once you have finished a board, compare its results at every table
    by matchpoints (`GET /boards/{board}/results`) and see all four hands
    (`GET /boards/{board}`); list any player's finished boards
    (`GET /users/{user}/playings`, yours at `GET /api/user/playings`) and
    their stats (`GET /users/{user}/stats`, yours at `GET /api/user/stats`); and
    review any finished playing of it call by call and trick by trick
    (`GET /playings/{playing}`), even after its table is gone, with what
    each hand could make double dummy and what each opening lead would have
    given (`double_dummy`, the table alone at
    `GET /boards/{board}/double-dummy`).

So a full lobby flow (browse, create, sit down, stand up, kick) works end to
end, through the auction, the play, the score and the next board — with
robots filling any seats nobody else takes — and a
player who stops playing doesn't block the table for long (mid-set it costs
their side the set once their one-minute turn clock runs out); afterwards the
results can be compared across tables by matchpoints and each playing
reviewed. IMPs aren't built.

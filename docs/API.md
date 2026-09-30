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

## Implemented & routed

| Method | Path | Controller@action | Auth | Returns |
|---|---|---|---|---|
| GET | `/` | closure | none | `{"Laravel": "<version>"}` — health check |
| GET | `/cards` | `Game\CardController@index` | none | all `Card` rows |
| GET | `/cards/{card}` | `Game\CardController@show` | none | one `Card` by id |
| GET | `/bids` | `Game\BidController@index` | none | the 38 calls with their ids (`bid_id` for `POST /tables/{table}/calls`), P, X, XX, then by rank |
| GET | `/tables` | `Game\TableController@index` | `auth` | all tables, newest first, with `seats.user`, `free_seats` and `can_manage` |
| POST | `/tables` | `Game\TableController@store` | `auth` | creates a table and seats the creator, with `robots` in the other three seats if asked (201) |
| GET | `/tables/{table}` | `Game\TableController@show` | `auth` | one table with `seats.user`, `free_seats` and `can_manage` |
| POST | `/tables/{table}/seats` | `Game\TableSeatController@store` | `auth` | take a free seat at an existing table, moving off your old one if you had one (201) |
| DELETE | `/tables/{table}/seats` | `Game\TableSeatController@destroy` | `auth` | give up your seat; deletes the table if you were the last player |
| POST | `/tables/{table}/seats/users` | `Game\TableSeatController@storeUser` | `auth` + `TablePolicy::manage` | a table manager seats another user (201) |
| POST | `/tables/{table}/seats/robots` | `Game\TableSeatController@storeRobot` | `auth` + `TablePolicy::manage` | a table manager puts a robot in a free seat (201) |
| DELETE | `/tables/{table}/seats/{user}` | `Game\TableSeatController@destroyUser` | `auth` (+ `TablePolicy::kick`: a manager, to remove anyone but yourself; anyone, to remove a robot from an unattended table) | quit your seat, or kick that player out |
| POST | `/tables/{table}/heartbeat` | `Game\TableSeatController@heartbeat` | `auth` + seated at the table (`TablePolicy::play`) | "still here": keeps the caller's seat from being freed as idle (200, `{last_seen_at}`) |
| GET | `/tables/{table}/playing` | `Game\PlayingController@show` | `auth` + seated at the table (`TablePolicy::play`) | the game state of the table's current board, with the caller's own hand |
| POST | `/tables/{table}/calls` | `Game\CallController@store` | `auth` + seated at the table (`TablePolicy::play`) | make your call in the auction (201, the updated game state) |
| POST | `/tables/{table}/playing/next` | `Game\PlayingController@next` | `auth` + seated at the table (`TablePolicy::play`); with `everyone`, `TablePolicy::manage` | once the board is finished, ask for the next one; the last of the four deals it (200, the game state) |
| POST | `/tables/{table}/cards` | `Game\CardPlayController@store` | `auth` + seated at the table (`TablePolicy::play`) | play the next card of the trick — yours, or dummy's as declarer (201, the updated game state) |
| POST | `/tables/{table}/claim` | `Game\ClaimController@store` | `auth` + seated at the table (`TablePolicy::play`) | claim some of the remaining tricks for your side, 0 to concede (201, the updated game state) |
| POST | `/tables/{table}/claim/response` | `Game\ClaimController@respond` | `auth` + seated at the table (`TablePolicy::play`) | accept or reject the pending claim (200, the updated game state) |
| DELETE | `/tables/{table}/claim` | `Game\ClaimController@destroy` | `auth` + seated at the table (`TablePolicy::play`) | withdraw your own pending claim (200, the updated game state) |
| GET | `/users?search=` | `UserController@index` | `auth` + `throttle:30,1` | find users by username or name (public profiles + `seated`, at most 10) |
| GET | `/users/{user}` | `UserController@show` | `auth` | another user's public profile (no email) |
| GET | `/users/{user}/playings` | `UserController@playings` | `auth` | that user's finished playings, latest first, paginated |
| GET | `/boards/{board}` | `Game\BoardController@show` | `auth` + finished that board (`BoardPolicy::view`) | the board with all four hands as dealt |
| GET | `/boards/{board}/results` | `Game\BoardController@results` | `auth` + finished that board (`BoardPolicy::view`) | every finished playing of the board, with matchpoints |
| GET | `/playings/{playing}` | `Game\PlayingController@review` | `auth` + finished that playing's board (`BoardPolicy::view`) | one finished playing with its auction and tricks, to review it |
| GET | `/api/user` | closure | `auth:sanctum` | current authenticated `User` (the caller's own record, email and `is_admin` included) |
| PATCH | `/api/user` | `UserController@update` | `auth:sanctum` | edit your own `name` / `description` |
| GET | `/api/user/playings` | `UserController@ownPlayings` | `auth:sanctum` | your own finished playings, as `GET /users/{user}/playings` |
| GET, POST | `/broadcasting/auth` | Laravel's `BroadcastController@authenticate` | session (`web` group) | signs a websocket subscription to a private channel, or 403 — see [Realtime](#realtime-websocket) and [`AUTH.md`](AUTH.md#websocket-channels-reverb) |

Example:
```bash
curl http://127.0.0.1:8000/cards
curl http://127.0.0.1:8000/cards/1
curl http://127.0.0.1:8000/bids
```

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
`seats` (`TableSeat` rows — `id`, `table_id`, `user_id`, `seat`, timestamps —
each with its `user`), `free_seats` (the unoccupied seats in `N, E, S, W`
order) and `can_manage`. The `TableUpdated` websocket event carries this same
shape less `can_manage` (see [Realtime](#realtime-websocket)).

`can_manage` (boolean) is whether **the caller** may manage the table —
seat other users and kick players — i.e. `TablePolicy::manage` evaluated for
them (see [`AUTH.md`](AUTH.md#authorization)): true for its `moderated_by`,
for its creator while seated there, and for any admin, seated or not. Show
the manager controls from it rather than re-deriving the rule from
`moderated_by`/`created_by`. In `GET /tables` it is per table, for the
caller.

A seated player's `user` is their **public profile** only
(`App\Http\Resources\UserResource`, via `TableSeatResource`): `id`, `name`,
`username`, `description`, `is_robot`. It never carries `email` (or anything
else from the `users` row), so listing tables does not reveal who plays under
which address. The same profile is served by `GET /users/{user}`.

**Robots.** A seat can hold a robot player instead of a human: a `users` row
with `is_robot: true`, username `robot-<n>`, from a pool that grows as it is
needed (`App\Services\RobotService`). Robots are seated by
`POST /tables` with `robots: true` or by a manager with
`POST /tables/{table}/seats/robots`; they bid, play, answer claims and ask
for the next board on their own, through the same rules as a human, about a
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

`board_id` is null until the table has all four players. Taking the **fourth**
seat deals the table a board and opens its playing, so the response to that
request is the first one to carry a non-null `board_id`. It goes back to null
if a player leaves before the board is finished.

### `GET /tables`
Every table. Ordered by `created_at` then `id`, newest first.

Tables always have at least one player: the API deletes a table when its
last player leaves, and the seeders create theirs the same way. That player
may be a robot, at an unattended table.

### `POST /tables`
Body (JSON, both optional):

| Field | Rules | Default |
|---|---|---|
| `name` | nullable string, max 255 | `null` |
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
- `board_id` stays null: a board is only dealt once all four seats are taken
  (see `POST /tables/{table}/seats`) — unless `robots` is true: then, in the
  same transaction, a robot takes each of the other three seats and the
  fourth one deals the first board, so the response already has a
  `board_id` and empty `free_seats`. The robots start calling at once if
  one of them deals; the human sees the auction reach their turn through
  `PlayingUpdated` (or `GET /tables/{table}/playing`).
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
    "seats": [{"id": 12, "table_id": 7, "user_id": 3, "seat": "E", "created_at": "...", "updated_at": "...",
               "user": {"id": 3, "name": "Ann", "username": "ann", "description": "Plays a strong club.", "is_robot": false}}],
    "free_seats": ["N", "S", "W"],
    "can_manage": true
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
`can_manage`.

### `POST /tables/{table}/seats`
Take a free seat at an existing table.

| Field | Rules | Default |
|---|---|---|
| `seat` | **required**, one of `N`, `E`, `S`, `W` | — |

- **201** with the updated table (same shape as `GET /tables/{table}`).
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
  - Moving **within** the same table is a plain seat change (e.g. `N` -> `E`).
    The table is not deleted on the way out even if you are its only player.
    Note a full table can never allow this, since every target seat is taken.
- If this was the **fourth** seat, the same transaction deals the table a board
  and opens its playing (`board_table` + four `board_table_seats` rows), so the
  returned table has a non-null `board_id` and an empty `free_seats`. The board
  is chosen by the rule in
  [`GAME-RULES.md` §8](GAME-RULES.md#board-selection-rule): one none of the
  four has played, else one where nobody holds a seat they have held on it
  before, else a freshly shuffled board. No request ever fails for want of a
  board.
- **409** if the seat is taken (`"Seat E is already taken."`) or the seat name
  is unknown. Already sitting somewhere is **no longer** a 409 — that is a
  move.
- **422** if `seat` is missing or not one of the four.
- **404** if the table id doesn't exist (tables are deleted when their last
  player leaves, so there is no "closed table" case).

### `DELETE /tables/{table}/seats`
Give up the seat you hold at this table. No body.

- **200** and the table is **deleted** if you were the last player:
  ```json
  {"status": 200, "message": "You left the table. Nobody was left, so the table was deleted.", "data": {"table_deleted": true}}
  ```
- **200** and the updated table (same shape as `GET /tables/{table}`) if
  players remain. If the leaver was `moderated_by`, the role passes to the
  creator if still seated, else the **human** who joined earliest
  (`table_seats.created_at`, then `id`) — never a robot; `created_by` is
  left alone. If only robots remain, the table becomes **unattended**
  (`unattended_since` set, `moderated_by: null`; see [Tables](#tables)).
  The seat you left stays free: no robot takes it.
- **409** `"You are not seated at this table."` if you hold no seat there.
- **404** if the table id doesn't exist.

If the table had a board under way that nobody had finished, leaving
**abandons** it: `tables.board_id` goes back to null and the `board_table` row
is detached (`table_id` set to null) rather than deleted, keeping its
`board_table_seats` snapshot so those four players are never dealt that deal
again; the calls and cards made so far are deleted. A finished playing is
untouched. Refilling the table deals a new
board.

Leaving is what frees a user to create another table, since
`table_seats.user_id` is unique (`POST /tables` still 409s a seated user).
Joining another table doesn't need it: `POST /tables/{table}/seats` moves you
there, leaving your old seat in the same transaction. Either way a user holds
**one** seat at a time and nothing is reserved for them: moving to another
table gives the first seat up for good, and the first table may well be gone
(or full) by the time they come back.

### `POST /tables/{table}/seats/users`
A table manager puts **another** user into a free seat. A table has exactly
one manager: its current `moderated_by` user, which also covers the creator
for as long as they sit there. Any `is_admin` user qualifies too, whether or
not they sit at the table, while a creator who has **left** does not
(`App\Policies\TablePolicy::manage`, see [`AUTH.md`](AUTH.md#authorization)).

| Field | Rules | Default |
|---|---|---|
| `user_id` | **required**, integer, an existing `users.id` | — |
| `seat` | **required**, one of `N`, `E`, `S`, `W` | — |

Find the `user_id` with [`GET /users?search=`](#get-userssearch), which also
flags users who are already `seated` somewhere (they would 409 here).

- **403** `"Only the table creator, its moderator or an admin can seat other
  players."` (Laravel's default `{message}` shape) for anyone else. This is
  checked before the body is validated.
- **201** with the updated table (same shape as `GET /tables/{table}`,
  message `"User seated successfully."`). Seating the **fourth** player deals
  the board and opens the playing, exactly as in
  `POST /tables/{table}/seats`.
- **409** if the seat is taken (`"Seat E is already taken."`) or the user
  already sits at any table (`"That user is already seated at a table."`).
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

- **403** `"Only the table creator, its moderator or an admin can seat a
  robot."` (Laravel's default `{message}` shape) for anyone else, checked
  before the body is validated.
- **201** with the updated table (same shape as `GET /tables/{table}`,
  message `"Robot seated successfully."`). Taking the **fourth** seat deals
  the board and opens the playing, exactly as for a human.
- **409** if the seat is taken (`"Seat E is already taken."`).
- **422** if `seat` is missing or not one of the four.
- **404** if the table id doesn't exist.

Robots are sent away like any player, with `DELETE /tables/{table}/seats/{user}`.

### `DELETE /tables/{table}/seats/{user}`
Take a player out of their seat. No body. `{user}` is a `users.id`.

- If `{user}` is the logged-in user this is a **quit** and is always allowed.
- Otherwise it is a **kick** and requires `TablePolicy::kick`: the table's
  manager (`TablePolicy::manage`) may kick anyone, and at an **unattended**
  table (only robots left) **any** logged-in user may kick a **robot**.
  Anyone else gets **403** `"Only the table creator, its moderator or an
  admin can remove other players."` (Laravel's default `{message}` shape).
- **200** and the updated table (same shape as `GET /tables/{table}`), with
  message `"You left the table."` for a quit or `"Player removed from the
  table."` for a kick. If the removed player was `moderated_by`, the role
  passes to the creator when they are still seated, otherwise to the human
  who joined earliest; `created_by` is left alone. Removing the last human
  leaves the table unattended, as `DELETE /tables/{table}/seats` does.
- **200** and the table is **deleted** if that was the last player:
  ```json
  {"status": 200, "message": "You left the table. Nobody was left, so the table was deleted.", "data": {"table_deleted": true}}
  ```
- Abandons an unfinished playing just as `DELETE /tables/{table}/seats` does.
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

The playing endpoints — `GET /tables/{table}/playing`,
`POST /tables/{table}/playing/next`, `POST /tables/{table}/calls`,
`POST /tables/{table}/cards` and the three claim endpoints — count as a heartbeat too (the `seen` route
middleware, `App\Http\Middleware\TouchTableSeat`), even when the call or
card itself is refused. Taking or changing a seat also sets it.

**Idle seats are freed.** The scheduled command `tables:release-idle-seats`
(every minute; see [`RUNNING.md`](RUNNING.md#scheduler-idle-seats)) frees the
seat of every **human** player (robots send no heartbeat and are never
idle) whose `last_seen_at` is older than
`BRIDGE_IDLE_SEAT_MINUTES` (default 5), or `BRIDGE_IDLE_PLAYING_SEAT_MINUTES`
(default 15) while their table has a board in its auction or play. It goes
through `TableSeatService::remove()`, so it is exactly a leave: `moderated_by`
is handed on, an unfinished playing is abandoned (the other three get
`board_id: null` and must refill the seat to get a new board), an emptied
table is deleted, and `TableUpdated` is broadcast. A finished board waiting
for `playing/next` uses the shorter timeout, since leaving then abandons
nothing. A client whose own seat vanished this way sees it in the next
`TableUpdated` (or a 403 from its next heartbeat) and should offer to rejoin.

The server can't learn about a disconnect from the websocket: Reverb doesn't
call back into Laravel when a connection drops, so the heartbeat — not a
presence channel — is what decides.

## Playing (game state)

Once the fourth seat is taken and a board is dealt, the table has a
**playing** (a `board_table` row). `GET /tables/{table}/playing` is its read
side; `POST /tables/{table}/calls` runs the auction,
`POST /tables/{table}/cards` the play and `/tables/{table}/claim` ends the
play early by agreement. A finished board carries its
duplicate score in `result` and the whole deal in `deal`, and stays on the
table until the players move on with `POST /tables/{table}/playing/next`.

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
    "board": {"id": 7, "number": 7, "dealer": "S", "vulnerable": "N-S E-W"},
    "players": {
      "N": {"id": 1, "name": "Ann", "username": "ann", "description": null, "is_robot": false},
      "E": {"id": 2, "name": "Bob", "username": "bob", "description": null, "is_robot": false},
      "S": {"id": 3, "name": "Cy", "username": "cy", "description": null, "is_robot": false},
      "W": {"id": 9, "name": "Robot 1", "username": "robot-1", "description": "A robot player.", "is_robot": true}
    },
    "turn": "N",
    "acting_user_id": 1,
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
    "result": null,
    "deal": null,
    "ready": null,
    "my_seat": "E",
    "hand": [
      {"id": 52, "suit": "S", "rank": 15, "rank_name": "Ace"},
      {"id": 47, "suit": "S", "rank": 9, "rank_name": "9"},
      {"id": 38, "suit": "H", "rank": 13, "rank_name": "Queen"}
    ]
  }
}
```

| Field | Meaning |
|---|---|
| `phase` | `waiting` — the table has no board (`tables.board_id` null, fewer than four players); `auction` — `board_table.auction_ended_at` is null; `play` — the auction ended with a contract (`finished_at` still null); `finished` — `finished_at` is set: after the 13th trick, when a claim is accepted, or straight away on a **passed out** board. |
| `playing_id` | the `board_table.id` |
| `board` | `id`, `number`, `dealer` (`N/E/S/W`) and `vulnerable` (a `Vulnerability` value) from `boards` |
| `players` | seat → public profile (`UserResource`, no email; `is_robot` marks a robot), from the playing's `board_table_seats` snapshot, not from `table_seats` |
| `turn` | the seat expected to act. During the `auction`: the dealer first, then clockwise after the last call. During the `play`: the **hand** the next card comes from — declarer's left-hand opponent leads the first trick, then clockwise, and each trick's winner leads the next. When it is dummy's seat, declarer plays it (see `acting_user_id`). `null` while `waiting` and once `finished` |
| `acting_user_id` | the id of the user who must act for `turn`: that seat's player, except that on dummy's turn it is **declarer**. A client compares it with its own user id to know it is its move (and, for declarer, that it is playing dummy's cards). `null` whenever `turn` is |
| `auction` | the calls made so far, in order: `{seat, bid}`, where `bid` is `{id, call, level, strain, special}` — `call` is the short name (`P`, `X`, `XX`, `1C`…`7NT`) and the only field telling pass, double and redouble apart; `level`/`strain` are null for those three. `[]` before the first call |
| `contract` | `null` during the auction and on a passed out board; once the auction ends with a bid, `{bid, doubled, declarer, dummy}` — `bid` shaped as above, `doubled` 0 (none), 1 (X) or 2 (XX), `declarer` the seat of the first player on the winning side to name the strain, `dummy` declarer's partner |
| `tricks` | the **complete** tricks, in order: `{round, leader, cards, winner}` — `round` 1–13, `leader` the seat that led, `cards` the four `{seat, card}` in the order played (`seat` is the hand the card came from, so dummy's seat for dummy's cards), `winner` the seat whose card won. `[]` until the first trick is complete. `null` whenever `contract` is |
| `current_trick` | the trick in progress, as `{seat, card}` in the order played: `[]` before the opening lead and between a trick's 4th card and the next lead (the finished trick is then the last of `tricks`). `null` whenever `contract` is |
| `tricks_won` | `{ns, ew}`: complete tricks won by each side so far. `null` whenever `contract` is |
| `dummy_hand` | dummy's **remaining** cards, face up to all four players (and on the table channel) once the opening lead is made; `null` before it, and whenever `contract` is. Same order and card shape as `hand`. Declarer plays these cards; dummy's own `hand` shows the same cards |
| `claim` | the **pending** claim (see [Claims](#claims-post-tablestableclaim-post-tablestableclaimresponse-delete-tablestableclaim)), else `null`: `{seat, tricks, hand, accepted}` — `seat` the claimer, `tricks` how many of the remaining tricks they claim for their side (`0` is a concession), `hand` the claimer's **remaining cards, face up to everyone** (on the table channel too) while the claim is pending, in `hand`'s order and card shape, and `accepted` the seats that have accepted it so far, in N, E, S, W order (`[]` at first). While it is non-null no card may be played; `turn` and `acting_user_id` don't change. Back to `null` once the claim is rejected, withdrawn or accepted (the board is then `finished` and `result.claimed` is `true`) |
| `result` | `null` until the phase is `finished`. Then `{contract, doubled, declarer, tricks_won, score_ns, made_by, claimed}`: `contract` is the final bid (shaped as `bid` above), `doubled` 0/1/2, `declarer` its seat, `tricks_won` the tricks declarer's side took, `score_ns` the duplicate score (`GAME-RULES.md` §6) **from N-S's point of view** — positive when N-S scored, negative when E-W did, whichever side declared — `made_by` the overtricks (`+1`), `0` for just made, or undertricks (`-2`), and `claimed` whether the play ended by an accepted claim rather than at trick 13 (`tricks_won` then includes the claimed tricks). A **passed out** board has `score_ns: 0`, `claimed: false` and every other field `null`. Example: `{"contract": {"id": 22, "call": "4S", ...}, "doubled": 0, "declarer": "E", "tricks_won": 11, "score_ns": -650, "made_by": 1, "claimed": false}` — E-W vulnerable, 4♠ by East making 11 |
| `deal` | `null` until the phase is `finished`. Then all four hands **as dealt** (from `board_card`, not what is left after the play): `{N: [...], E: [...], S: [...], W: [...]}`, each in `hand`'s order and card shape. Public — it is on the table channel too — since the board is over |
| `ready` | `null` until the phase is `finished`. Then the seats whose players have asked for the next board (`POST /tables/{table}/playing/next`), in N, E, S, W order: `[]` right after the board ends |
| `my_seat` | the caller's seat in the snapshot |
| `hand` | the caller's **own** cards only: the 13 `board_card` rows for their seat, less any card already in `cardplays`, sorted spades, hearts, diamonds, clubs and high to low within a suit. Card `rank` is 2–10, J=12, Q=13, K=14, A=15. Apart from this, the only cards in the payload are face up: those in `tricks` / `current_trick`, after the opening lead `dummy_hand`, a pending claim's `claim.hand`, and once the board is finished `deal` |

A card is `{id, suit, rank, rank_name}` everywhere it appears.

While `phase` is `waiting`, **every other field is null** (including `hand`,
`my_seat` and `auction`).

### `POST /tables/{table}/playing/next`
Once the board is `finished` (13 tricks played, or passed out), ask for the
next one. The finished board stays on the table — `result` and the whole
`deal` on show — until **every** player has asked; the last one to ask deals
the next board. Built by `App\Services\BoardSelectionService::moveOn()`.

| Field | Rules |
|---|---|
| `everyone` | optional boolean. `true` asks for all four at once: a table manager's call (`TablePolicy::manage` — the moderator, a still-seated creator, or an admin, seated or not) |

- **200** with the game state, exactly what `GET /tables/{table}/playing`
  would now return:
  - message `"Waiting for the other players."` while somebody has still to
    ask — still the `finished` board, with the caller's seat now in `ready`;
  - message `"Next board dealt."` once the last player asks — phase
    `auction` on the new board, with the caller's new `hand`.
  Asking again changes nothing (same 200, no broadcast).
- **409** (`sendError`, `data: []`), nothing stored:
  - `"The board is not finished yet."` — the phase is `auction` or `play`;
  - `"The table has no board yet: ..."` — the phase is `waiting`;
  - `"The table is short of a player: ..."` — somebody left after the board
    ended; there's nothing to confirm, the next board is dealt as soon as a
    fourth player sits down (as for the first board).
- **403** for anyone not seated at this table, and for `everyone` from
  anyone but a manager; **401** for guests, **404** for an unknown table id.

The next board is picked by the same rule as the first (`GAME-RULES.md` §8:
none of the four has played it, else nobody has played it from the seat they
hold, else a freshly shuffled one; never a board this table has played),
for the same four players in the same seats. `tables.board_id` moves to it
and a new `board_table` row and seat snapshot are opened; the finished one
stays as it is. It sends what the fourth seat being taken sends:
`TableUpdated`, `PlayingUpdated` and one `HandDealt` per player. A player
newly asking sends `PlayingUpdated` (for `ready`).

Who has asked is `board_table_seats.ready_at` on the finished playing.
Leaving between boards detaches nothing (the playing is finished): the table
keeps showing the finished board until a fourth player sits down.

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
- **422** (default Laravel shape) for a missing or unknown `bid_id`.
- **403** for anyone not seated at this table (checked before validation),
  **401** for guests, **404** for an unknown table id.

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

### `POST /tables/{table}/cards`
Play the next card of the current trick. Built by
`App\Services\CardPlayService::play()`; the rules are in
[`GAME-RULES.md`](GAME-RULES.md) §5.

| Field | Rules |
|---|---|
| `card_id` | required; one of the 52 `cards` rows |

Each player plays their own hand, **except dummy's, which declarer plays**:
on dummy's turn (`turn` is dummy's seat, `acting_user_id` is declarer's id)
declarer sends one of dummy's cards. Dummy's own player never plays. The row
stored in `cardplays` has the caller as `user_id` and the hand the card came
from as `seat`, plus `round` (trick 1–13) and `order` (1–4).

- **201** with the updated state — exactly what `GET /tables/{table}/playing`
  would now return, `hand` and `my_seat` included — message
  `"Card played successfully."`
- **409** (`sendError`, `data: []`) when the card can't be played, with the
  reason as the message:
  - `"It is not your turn: E plays next."` — names the hand to play from
    (dummy's seat when declarer should play from dummy);
  - `"Dummy doesn't play: declarer plays dummy's cards."` — the caller is
    dummy, whatever the turn;
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
neither claim nor answer. While the claim is pending the state's `claim`
shows the claimer's remaining cards face up to everyone, no card may be
played and no other claim made; `turn` doesn't move.

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
  `{seat, tricks, hand, accepted: []}`.
- **409**:
  - `"You can claim between 0 and 12 tricks: 12 remain to be played."`;
  - `"A claim is already pending: N claims 10."`;
  - `"Dummy takes no part in a claim: declarer claims for declarer's side."`;
  - `"The auction is not over yet."`, `"The board is finished."`, or
    `"The table has no board yet: ..."` — the phase isn't `play`.

**`POST /tables/{table}/claim/response`** — accept or reject the pending claim.

| Field | Rules |
|---|---|
| `accept` | required boolean |

- **200**. A **reject** clears the claim at once (`claim: null`, message
  `"Claim rejected: play goes on."`) and play resumes where it stopped. An
  **accept** adds the caller's seat to `claim.accepted` (`"Claim accepted."`);
  the last one needed finishes the board (`"Claim accepted: the board is
  finished."`): declarer's `tricks_won` = tricks won so far plus the claimed
  share of the rest — `tricks` if the claimer is on declarer's side, the
  remaining tricks less `tricks` otherwise — scored through
  `BoardTable::finish()` like a 13th trick. The phase is then `finished`,
  `claim` is `null` and `result.claimed` is `true`.
- **409**: `"There is no claim to answer."`; `"You made this claim: withdraw
  it instead."`; `"You have already accepted this claim."`; dummy's
  `"Dummy takes no part in a claim: ..."`; or the phase messages above.
- **422** for a missing or non-boolean `accept`.

**`DELETE /tables/{table}/claim`** — the claimer withdraws their pending
claim. No body.

- **200**, message `"Claim withdrawn: play goes on."`, `claim: null`.
- **409**: `"There is no claim to withdraw."`; `"Only the claimer (N) may
  withdraw the claim."`; dummy's or the phase messages above.

A player leaving (or being released as idle) while a claim is pending
detaches the playing as at any other point of the board; the claim goes
with it.

## Boards (results across tables)

A board is played at many tables; these compare what each table made of it
(duplicate bridge, `GAME-RULES.md` §6). All three endpoints are only for players
who have **finished** the board at some table — a `board_table_seats` row on
one of its playings with `finished_at` set (`BoardPolicy::view`, through
`BoardResultsService::hasFinished()`). Anyone else might still be dealt it,
so they get **403** (Laravel's default `{message}` shape) — including a
player who left mid-board (their playing was detached unfinished), and
admins. **401** for guests, **404** for an unknown board id.

All three read `board_table` and its seat snapshot, which outlive the table: a
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
        "players": {"N": {"id": 1, "name": "Ann", "username": "ann", "description": null, "is_robot": false}, "E": {...}, "S": {...}, "W": {...}},
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
`ready`** (which only means something at a live table) and without the
viewer's `my_seat` / `hand`: `playing_id`, `board`, `players` (from the seat
snapshot), `turn` and `acting_user_id` (both `null`), `auction` (every call
in order), `contract`, `tricks` (the complete tricks in order),
`current_trick`, `tricks_won`, `dummy_hand`, `claim` (`null`), `result` and
`deal` (all four hands as dealt). It works the same once the table has been
deleted.

A board that ended by an accepted claim has only the tricks played up to the
claim (the unfinished one in `current_trick`, dummy's unplayed cards in
`dummy_hand`) and `result.claimed: true`. A passed out board has its four
passes in `auction` and `contract`, `tricks` etc. `null`.

**Playings finished before this endpoint existed** (branch `34-board-review`)
lost their calls and cards when their table was deleted: they come back with
`auction: []` and, when there was a contract, `tricks: []` — the contract,
result and deal are still there.

## Users

A user's profile has two views:

- **Public** (`UserResource`): `id`, `name`, `username`, `description`,
  `is_robot` (true for a robot player, see [Tables](#tables)). This is
  what other players see — in `GET /users/{user}`, in `GET /users?search=`
  (which adds `seated`) and nested in every table payload.
- **Own**: the full serialised `User` (adds `email`, `email_verified_at`,
  timestamps; `password` and `remember_token` stay hidden) plus `is_admin`
  (boolean, read-only). Only the user themselves gets it, from
  `GET /api/user` and `PATCH /api/user`; `is_admin` is hidden from every other
  view of a user. A client doesn't need it to decide what a player may do at
  a table — use the table's `can_manage`.

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
    {"id": 3, "name": "Ann", "username": "ann", "description": "Plays a strong club.", "is_robot": false, "seated": false},
    {"id": 7, "name": "Joanna", "username": "jo", "description": null, "is_robot": false, "seated": true}
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
  "data": {"id": 3, "name": "Ann", "username": "ann", "description": "Plays a strong club.", "is_robot": false}
}
```

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
| `board` | `id`, `number`, `dealer`, `vulnerable` |
| `seat` | the seat the user held, from the snapshot |
| `partner` | the player in the opposite seat (`UserResource`) |
| `contract`, `doubled`, `declarer`, `tricks_won`, `score_ns`, `made_by`, `claimed` | the game state's `result` |
| `score` | the same score from the **user's** side: `score_ns` for N/S, negated for E/W |

Matchpoints aren't in the history; read them from
`GET /boards/{board}/results`.

### `PATCH /api/user`
The logged-in user edits their **own** profile (in `routes/api.php`, behind
`auth:sanctum`, next to `GET /api/user`). There is no user id in the URL, so
nobody can edit anyone else's profile.

| Field | Rules |
|---|---|
| `name` | optional; if present, a non-empty string, max 255 |
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
| `private-App.Models.User.{id}` | `echo.private('App.Models.User.' + id)` | user `{id}` only | `HandDealt` — one player's own cards |

A client should subscribe to its table's channel **after** it has a seat
(the subscription is refused otherwise), and re-subscribe after moving to
another table. Leaving doesn't end the subscription server-side; the client
should unsubscribe (`echo.leave('table.' + id)`).

### Event `TableUpdated`

Event name on the wire: `App\Events\TableUpdated` (Echo:
`.listen('TableUpdated', ...)`). Class `App\Events\TableUpdated`, on
`private-table.{id}`.

**Sent when** a seat at that table changes — every path goes through
`TableSeatService`:
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
- the fourth seat is taken, dealing a board: that event is the first with a
  non-null `board_id`; a player leaving mid-board sends it back to null.

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
        "created_at": "...", "updated_at": "...",
        "user": {"id": 12, "name": "Alice", "username": "alice", "description": null, "is_robot": false}
      },
      {
        "id": 22, "table_id": 7, "user_id": 13, "seat": "E",
        "created_at": "...", "updated_at": "...",
        "user": {"id": 13, "name": "Bob", "username": "bob", "description": null, "is_robot": false}
      }
    ],
    "free_seats": ["S", "W"]
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
- the fourth seat is taken and a board is dealt — in the same request as the
  `TableUpdated` that first carries a non-null `board_id`;
- a call is accepted (`POST /tables/{table}/calls`), including the one that
  ends the auction;
- a card is played (`POST /tables/{table}/cards`), including the last one;
- a claim is made, accepted, rejected or withdrawn (the three
  `/tables/{table}/claim` endpoints), including the accept that finishes the
  board;
- a player asks for the next board (`POST /tables/{table}/playing/next`) —
  `ready` changes — and again when the last one deals it;
- a **robot** does any of the above: its moves go through the same services,
  so each one sends `PlayingUpdated` exactly like a human's. Robot moves come
  about a second apart, so a board with robots streams one event per move.

It is **not** sent when a player leaving abandons the board; that shows up as
`TableUpdated` with `board_id` back to null.

**Payload** — `{playing}`, the **public** part of
`GET /tables/{table}/playing`: `phase`, `playing_id`, `board`, `players`,
`turn`, `acting_user_id`, `auction`, `contract`, `tricks`, `current_trick`,
`tricks_won`, `dummy_hand`, `claim`, `result`, `deal`, `ready`. It never carries `hand` or `my_seat`: the table
channel is only authorised at subscribe time, so a player who has left may
still be listening. The only cards in it are face up — the ones played,
after the opening lead dummy's, the claimer's while a claim is pending, and
once the board is finished the whole deal.

```json
{
  "playing": {
    "phase": "auction",
    "playing_id": 42,
    "board": {"id": 7, "number": 7, "dealer": "S", "vulnerable": "N-S E-W"},
    "players": {"N": {"id": 1, "name": "Ann", "username": "ann", "description": null}, "E": {...}, "S": {...}, "W": {...}},
    "turn": "S",
    "acting_user_id": 3,
    "auction": [],
    "contract": null,
    "tricks": null,
    "current_trick": null,
    "tricks_won": null,
    "dummy_hand": null,
    "claim": null,
    "result": null,
    "deal": null,
    "ready": null
  }
}
```

### Event `HandDealt`

Class `App\Events\HandDealt`, on `private-App.Models.User.{id}` (Echo:
`echo.private('App.Models.User.' + myId).listen('HandDealt', ...)`). Same
delivery as `TableUpdated`.

**Sent when** a board is dealt (the first, or the next one after
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

Calls, cards and claims re-send `PlayingUpdated` rather than add new table events.
A played card needs no per-player event: it only takes a card out of one
hand, which that player's client can drop itself (or re-read from
`GET /tables/{table}/playing`).

## Stubs and not built

- No endpoint yet for: renaming or transferring a table by hand, or
  assigning a board to a table by hand (one is dealt automatically when the
  table fills, and after each finished board once the players move on).
- No ban list: kicking a player doesn't stop them rejoining.
- Robots bid a SAYC-style system and play by rules of thumb
  ([`ROBOTS.md`](ROBOTS.md)); better card play is planned as a separate
  issue. Robots never redouble or claim. The robots work out a short
  explanation of every call (ready for bid alerts), but no endpoint or
  event sends it yet.
- No presence channel ("who is online"): idle players are detected by the
  heartbeat only.

## Practical implication for a frontend right now

You can currently only:
1. Register/login/logout (session-based, see [`AUTH.md`](AUTH.md))
2. List/show cards, and list the 38 bids with their ids (`GET /bids`)
3. List tables, show one table with its free seats, and create a table
   (the creator is seated, up to 3 active tables per creator)
4. Join a free seat at another table, and leave your seat — which deletes the
   table if you were the last player, or hands it to the creator (if still
   seated) or the earliest remaining player if you were the moderator
5. As a table's manager (its moderator, the creator while seated, or an
   admin), find a user by username or name (`GET /users?search=`) and seat
   them at it, or kick a player out of it
6. Read any player's public profile, and edit your own name and description
7. Subscribe to your table's websocket channel and get every seat change
   (and the board being dealt) pushed as `TableUpdated`, instead of polling
8. Once four players sit down, read the dealt board with
   `GET /tables/{table}/playing` — board number, dealer, vulnerability, the
   four players, whose turn it is and your own 13 cards — and get the same
   pushed as `PlayingUpdated` (table channel) and `HandDealt` (your own
   channel)
9. Bid with `POST /tables/{table}/calls`, in turn, with illegal calls refused
   (409 with the reason), until the auction ends with a contract, declarer and
   dummy — or is passed out. Every call is pushed as `PlayingUpdated`.
10. Play the 13 tricks with `POST /tables/{table}/cards` — declarer plays
    dummy's cards, dummy is face up after the opening lead, follow suit is
    enforced and each trick's winner leads the next. Every card is pushed as
    `PlayingUpdated`; after the last trick the board is `finished` with
    declarer's tricks and its duplicate score (`result`) saved. Or cut the
    play short: claim or concede the remaining tricks
    (`POST /tables/{table}/claim`), which the other non-dummy players accept
    or reject (`POST /tables/{table}/claim/response`); an accepted claim
    scores the board the same way.
11. Move on to the next board with `POST /tables/{table}/playing/next`.
12. Keep your seat with `POST /tables/{table}/heartbeat` every ~30 s: a
    player who goes quiet (closed tab, lost connection) has their seat freed
    after 5 minutes, or 15 during a board.
13. Play alone against robots: `POST /tables` with `robots: true` deals a
    board at once, or a manager fills any free seat with
    `POST /tables/{table}/seats/robots`. The robots bid, play, answer claims
    and move on to the next board by themselves while a human is seated.
14. Once you have finished a board, compare its results at every table
    by matchpoints (`GET /boards/{board}/results`) and see all four hands
    (`GET /boards/{board}`); list any player's finished boards
    (`GET /users/{user}/playings`, yours at `GET /api/user/playings`); and
    review any finished playing of it call by call and trick by trick
    (`GET /playings/{playing}`), even after its table is gone.

So a full lobby flow (browse, create, sit down, stand up, kick) works end to
end, through the auction, the play, the score and the next board — with
robots filling any seats nobody else takes — and a
player who disappears doesn't block the table for long; afterwards the
results can be compared across tables by matchpoints and each playing
reviewed. IMPs aren't built.

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# bridge_backend

Laravel 11 / PHP 8.2 backend for an online **contract bridge** app (4 players
at a table bid in an auction, then play 13 tricks on a pre-dealt board; the
schema is shaped for duplicate bridge). Uses Sanctum (SPA/cookie auth) and
MySQL. GitHub repo: https://github.com/bulbulica2/bridge_backend. Branches
are named `<issue-title-prefix>-<topic>` (e.g. `7-fix-database`, tracked by
the GitHub issue whose title starts with it) and merged to `main` via PR;
commit messages are prefixed with the branch name.

If you don't know bridge rules (auction legality, declarer/dummy, trick
winner, scoring), read `../bridge_docs/GAME-RULES.md` before touching game
logic — it also maps each rule onto the tables below and lists what isn't
enforced yet.

## Commands

Local stack is XAMPP (MySQL on 3306, DB `bridge`, user `root`, no password).

```bash
php artisan serve                 # API on http://127.0.0.1:8000
php artisan migrate:fresh --seed  # rebuild DB with sample data
php artisan route:list            # actual registered routes
php artisan test                  # all tests (PHPUnit 11)
php artisan test --filter=RegistrationTest            # one class
php artisan test --filter=test_new_users_can_register # one method
vendor/bin/pint                   # format (Laravel Pint, default preset)
```

- Tests run on in-memory sqlite (`phpunit.xml`), so they don't touch the
  MySQL `bridge` DB and don't need MySQL running. Keep migrations
  sqlite-compatible.
- `AppServiceProvider::register` force-enables laravel-debugbar only when
  `APP_ENV=local`. Don't make it unconditional: `enable()` skips debugbar's
  own testing check, and the HTML it injects breaks `assertNoContent()`.
- Breeze auth is lightly customized: `/register` also requires `username`
  (NOT NULL, unique on `users`).
- Migrations are edited in place (nothing has shipped), so after pulling
  schema changes run `php artisan migrate:fresh --seed`; plain `migrate`
  won't see them.
- `composer dev` won't work: it runs `npm run dev`, but there's no
  `package.json` (only a leftover `package-lock.json`). Use `php artisan serve`.
- Code style: 2-space indentation (`.editorconfig`), including PHP.

## Architecture

- **Routing** (`bootstrap/app.php`): game endpoints live in `routes/web.php`
  (not `api.php`) so they share the session/cookie stack; `routes/auth.php`
  (stock Breeze, API-only — no views) is `require`d from `web.php`.
  `routes/api.php` only has `GET /api/user`. Sanctum's
  `EnsureFrontendRequestsAreStateful` is prepended to the api group.
- **Controllers**: game controllers are in `app/Http/Controllers/Game/` and
  extend `BaseController`, whose `sendResponse($data, $message, $code)` and
  `sendError($message, $code, $errors = [])` both return
  `{status, message, data}` — use them for new endpoints. Validation goes in
  `app/Http/Requests/<Area>/` form requests. `TableController` has
  `index/store/show` and `TableSeatController` has `store`/`destroy`
  (join/leave a seat) plus `storeUser` (`POST /tables/{table}/seats/users`,
  a manager seats someone else) and `destroyUser`
  (`DELETE /tables/{table}/seats/{user}`, quit if it's your own seat,
  otherwise a manager kicking that player), all behind the `auth` middleware. Both serialise a
  table through `App\Http\Resources\TableResource` (model fields plus
  `free_seats`), so every table payload has the same shape. `AuctionController`
  (outside `Game/`) and `Game\BidController` are empty.
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
  to the creator if they are still seated, else the earliest-joined remaining
  player. Controllers map the exception to `sendError(..., 409)` on
  `DELETE /tables/{table}/seats` and to 404 on
  `DELETE /tables/{table}/seats/{user}`, where the seat is named in the URL.
  Reuse the service for join/move instead of re-checking; both `seat()` and
  `remove()` take an optional `$by` (the acting user) for when it isn't the
  user being seated or removed, so the error names "that user". Being kicked
  isn't recorded anywhere — there is no ban list, so a kicked player can
  rejoin at once. Both also call into `BoardSelectionService` (below), so
  they mutate the passed-in `$table`'s `board_id`.
- **Boards**: `App\Services\BoardSelectionService` owns which board a table
  plays. `startPlayingIfFull()` fires from `seat()` when the **fourth** seat
  is taken — not at `POST /tables`, because the selection rule needs all four
  players' history and `board_table_seats` snapshots four seats. It applies
  the §8 rule (a board none of the four has played, else one where nobody
  holds a seat they've held on it, else `dealBoard()` shuffles a brand-new
  one), sets `tables.board_id` and opens the `board_table` playing.
  `abandonPlaying()` fires from `remove()`: an unfinished playing is
  **detached** (`table_id` set to null), never deleted, so the seat snapshot
  keeps recording that those four saw the deal. `dealBoard()` needs the 52
  seeded cards and throws otherwise; boards with no `board_card` rows are
  never selected.
- **Authorization**: `App\Policies\TablePolicy::manage` (auto-discovered) is
  true for a table's `moderated_by`, any `is_admin` user, or its `created_by`
  **while that creator still holds a seat there** — a table has exactly one
  manager, and a creator who left has already handed the role on. Check it in
  the form request's `authorize()` so non-managers get 403 before validation
  (`AddUserToSeatRequest`, `RemoveUserFromSeatRequest`).
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
    (`Table::active()` scope). There is no closed/archived state: the last
    player to leave deletes the row. A user may hold at most
    `Table::MAX_ACTIVE_PER_CREATOR` (3) active tables as `created_by`.
    `created_by` never moves, which is why it is what that limit counts;
    `moderated_by` does move, to the earliest-joined player left when the
    current moderator leaves.
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
    rule in `../bridge_docs/GAME-RULES.md` §8. `tables.board_id` is only the
    current board. Because tables are deleted rather than closed,
    `board_table.table_id` is nullable and `nullOnDelete`: a playing and its
    `board_table_seats` snapshot outlive the table, so a user's board history
    survives; an unfinished playing is detached the same way when a player
    leaves mid-board. The call-by-call and card-by-card logs still die with
    the table, but no FK does it (they hang off `board_table`, which
    survives): a `Table::deleting` hook calls `BoardTable::discardLogs()` on
    each of its playings, and `abandonPlaying()` discards a detached
    playing's logs. The contract and result saved on `board_table` remain.
    Delete tables through Eloquent (`$table->delete()`), not a query-builder
    delete, or the hook won't run.
- **Seeding** (`DatabaseSeeder`) branches on `APP_ENV`: `production` seeds only
  cards, bids and 100 dealt boards (through `BoardSeeder`, so they have
  hands); anything else also seeds a fixed admin user (`email@email.com` /
  `pass`), random users, tables, seats, auctions and card plays. Seeders are
  split between `database/seeders/game/` (namespace
  `Database\Seeders\game`) and the root seeders folder. Seeded auctions and
  card plays are random and don't follow bridge rules. `TableSeatSeeder`
  only seats users without a seat, so some tables stay partly or completely
  empty. A completely empty seeded table is **inactive** — a state the API
  itself never leaves behind, since leaving deletes the table.
- Board selection (`GAME-RULES.md` §8) is implemented; no other game rules
  are enforced yet (turn order, bid legality, follow suit, trick winner,
  scoring).
- `AuctionFactory`/`CardplayFactory` default `board_table_id` to a fresh
  `BoardTable::factory()`, whose board has no `board_card` rows. Such boards
  are inert — board selection only considers boards with a full 52-card deal.
  The seeders don't use those factories (`AuctionSeeder`/`CardplaySeeder`
  write onto the seeded playings), so a seeded DB has only dealt boards.

## Keep API docs in sync — do this in every relevant change, not as a follow-up

Docs for this API live at `../bridge_docs/backend`
(`C:\xampp\htdocs\bridge_docs\backend`), a sibling project docs folder shared
with the (future) frontend:

| File | Update it when you change... |
|---|---|
| `API.md` | any route (add/remove/rename), a controller action's request params, or a response's JSON shape/fields |
| `DATA-MODEL.md` | a model's fillable fields, relations, casts, or a migration (new table/column, enum values, FK) |
| `AUTH.md` | auth routes/middleware, Sanctum config (`config/sanctum.php`), CORS config, or how a client is expected to authenticate |
| `RUNNING.md` | local setup/run steps, `.env` keys required to run the app, or seeders/commands needed to get a working local DB |
| `README.md` | none of the above changed but the overall status/summary line (branch/commit reference) is stale |
| `../GAME-RULES.md` | you implement or change enforcement of a bridge rule (auction, play, scoring), or its "In code" / status notes become wrong |

Rules:
- Treat this as part of the same change, in the same turn — not a separate
  pass or a TODO. If you touch a route file, a controller, a migration, or a
  model's `$fillable`/relations, check whether `bridge_docs/backend` needs a
  matching edit before considering the task done.
- If one logical change touches multiple files above (e.g. a new endpoint
  with a new model field), update all of the relevant docs together.
- Docs must reflect actual current behavior, never aspiration. Explicitly
  mark endpoints/fields as implemented vs. stubbed (exists but empty) vs.
  planned-but-not-built (e.g. commented-out migrations), the way the
  existing docs do — don't silently document something as working if it
  isn't wired up yet.
- If you're unsure whether a change is "doc-worthy," err toward updating —
  stale API docs are worse than a redundant edit.

# Running the backend locally

Requirements: PHP 8.2+ with `pdo_mysql` and `pdo_sqlite`, Composer 2, MySQL
running (XAMPP's MySQL on port 3306 works fine). `vendor/` isn't committed;
`composer install` builds it from `composer.lock`.

```bash
cd C:\xampp\htdocs\bridge_backend

# first time only: create the database if it doesn't exist
mysql -u root -e "CREATE DATABASE IF NOT EXISTS bridge"

# first time / after pulling new dependencies
composer install

# first time only: local config (.env.example has working local values)
cp .env.example .env
php artisan key:generate

# first time: create tables and seed sample data
# (boards, users, tables, seats, auctions, plays — see "Seeded data" below)
php artisan migrate --seed

# start the API
php artisan serve
```

For live table updates, two more processes run next to it, each in its own
terminal (see [Realtime](#realtime-reverb) below):
```bash
php artisan reverb:start            # websocket server, ws://localhost:8080
php artisan queue:work --sleep=0.1  # sends the queued broadcasts to Reverb
```
The API works without them: seat changes still succeed, the broadcasts just
wait in the `jobs` table until a worker runs.

To free the seats of players who closed the tab, also run the scheduler (see
[Scheduler](#scheduler-idle-seats) below):
```bash
php artisan schedule:work           # runs tables:release-idle-seats every minute
```

**After pulling migration changes, rebuild the DB:**
```bash
php artisan migrate:fresh --seed
```
Migrations are still edited in place rather than added as new files (most
recently `33-claims`, which added the pending-claim columns to
`board_table`). A plain `php artisan migrate`
sees nothing new and leaves the old schema. `migrate:fresh` drops every
table, so local data is lost.

### Seeded data

Outside `APP_ENV=production` the seeders play every table through the game
services (`TableSeatService`, `AuctionService`, `CardPlayService`,
`ClaimService`), so every seat, call, card and claim is one the API would have accepted and
`GET /tables/{table}/playing` reads sensible turns, tricks and scores. You
get one table per phase, named after it:

| Table | State |
|---|---|
| `Your call` | full, mid-auction, and it is the admin's turn to call |
| `Bidding` | full, mid-auction |
| `Playing` | full, contract reached, between 1 and 51 cards played |
| `Finished` | all 13 tricks played and scored, waiting for the next board |
| `Claimed` | stopped mid-play by declarer's claim of a random share of the remaining tricks, which both defenders accepted; scored, waiting for the next board |
| `Passed out` | four passes, finished with score 0 |
| `Waiting for players` | 2 players, no board yet |

Log in as the admin (`email@email.com` / `pass`) to act at `Your call`.
The other seeded users all have the password `password`. Calls, cards and
dealt boards are random, so each `migrate:fresh --seed` gives a different
game. The seeders don't queue any broadcasts.

The seeded players never send heartbeats, so if `schedule:work` is running
their seats are freed like anyone else's (after 5 minutes, or 15 at a table
mid-board), which deletes their tables. Re-seed to get them back. The
admin's heartbeat only keeps the admin's own seat.

Check migration status without applying anything:
```bash
php artisan migrate:status
```

Server listens on `http://127.0.0.1:8000` (matches `APP_URL` in `.env`).

Config of note (`.env`):
- `DB_DATABASE=bridge`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_USERNAME=root`, no password
- `FRONTEND_URL` — the SPA's origin, used for CORS `allowed_origins` and
  Sanctum's stateful domains; not in `.env.example`, so it defaults to
  `http://localhost:3000`
- `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`
- `BROADCAST_CONNECTION=reverb` and the `REVERB_*` keys — see below

## Realtime (Reverb)

**Decision (`16-realtime-transport`):** table updates reach the players over
websockets served by **Laravel Reverb**, not Pusher and not polling.
- Polling can't give a turn-based game sub-second updates without every
  client hitting `GET /tables/{table}` several times a second.
- Reverb is first-party, self-hosted and needs no third-party account; the
  players' hands, once the game broadcasts them, never leave our server.
- Reverb speaks the Pusher protocol and uses Laravel's broadcasting API, so
  moving to hosted Pusher later (e.g. for a host that can't run long-lived
  processes) is a `.env` change, not a code change.
- The cost is two long-running processes next to `php artisan serve`.

What runs where:

| Process | Command | Why |
|---|---|---|
| API | `php artisan serve` | HTTP, including `POST /broadcasting/auth` |
| Websocket server | `php artisan reverb:start` (add `--debug` to log every frame) | holds the players' connections on port 8080 |
| Queue worker | `php artisan queue:work --sleep=0.1` | broadcasts are queued jobs; the worker sends them to Reverb |

Broadcast events implement `ShouldBroadcast`, so they go through the queue: a
Reverb server that is down fails a queued job, not the player's request. The
queue is the `database` driver, which polls; `--sleep=0.1` makes an idle
worker check every 100 ms instead of the default 3 s, which otherwise shows as
a 3-second lag on every update. Measured locally, a seat change reaches the
other players ~0.3 s after the HTTP response. In production use a Redis queue
(it blocks rather than polls) and run both processes under a supervisor
(systemd/Supervisor), with a reverse proxy terminating TLS for `wss://`.

Restart `queue:work` and `reverb:start` after changing PHP code — both are
long-running and keep the old code loaded.

`.env` keys (`.env.example` has working local values):

| Key | Local value | Meaning |
|---|---|---|
| `BROADCAST_CONNECTION` | `reverb` | `log` writes broadcasts to the log instead; `null` drops them |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | any strings | shared by the app and the Reverb server; the **key** is public (clients connect with it), the **secret** signs channel auth and must stay private. Generate real ones for production (`php artisan reverb:install`) |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | `localhost` / `8080` / `http` | where the app sends broadcasts, and what clients connect to |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | unset (`0.0.0.0` / `8080`) | what `reverb:start` binds to (`config/reverb.php`) |

An existing `.env` from before this branch needs those keys added (copy them
from `.env.example`) or broadcasts fail in the queue worker.

## Scheduler (idle seats)

`routes/console.php` schedules `tables:release-idle-seats` every minute: it
frees the seat of every player who has sent no heartbeat or playing request
for too long, exactly as if they had left (see
[`API.md`](API.md#post-tablestableheartbeat)). Locally run
`php artisan schedule:work` in its own terminal; in production use one cron
entry, `* * * * * php /path/to/artisan schedule:run`. Run the sweep once by
hand with `php artisan tables:release-idle-seats`. Without the scheduler
nothing is freed and a player who vanished keeps their seat until somebody
kicks them.

| Key | Default | Meaning |
|---|---|---|
| `BRIDGE_IDLE_SEAT_MINUTES` | `5` | minutes without a sign of life before a seat is freed (`config/bridge.php`) |
| `BRIDGE_IDLE_PLAYING_SEAT_MINUTES` | `15` | the same while the table is in the middle of a board, where freeing the seat abandons it for the other three |

Like `queue:work`, restart `schedule:work` after changing PHP code.

## Tests

```bash
php artisan test
```

`phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`, so tests
run against an in-memory sqlite DB and **don't touch the MySQL `bridge`
database**. MySQL doesn't need to be running for tests, and neither does a
`.env`: `phpunit.xml` also sets a test-only `APP_KEY`.

Two things can override `phpunit.xml`, and both used to point the suite
(and `RefreshDatabase`, which wipes whatever it runs on) at the MySQL dev DB:
- **A cached config.** `php artisan config:cache` writes
  `bootstrap/cache/config.php` (gitignored, so per machine), and a cached
  config ignores env vars. `phpunit.xml` now sets
  `APP_CONFIG_CACHE=bootstrap/cache/config.testing.php`, so tests never load
  the dev cache — it no longer matters whether one exists.
- **A variable exported in the shell** (`DB_CONNECTION`, `DB_DATABASE`,
  `APP_ENV`): PHPUnit doesn't overwrite an env var that is already set.

As a backstop, `tests/TestCase.php` checks the booted config before any test
trait runs and fails every test, without connecting to anything, unless it is
`APP_ENV=testing` on in-memory sqlite:
```
Tests must run with APP_ENV=testing on in-memory sqlite, got APP_ENV=local on mysql (...).
A cached config or a DB_*/APP_ENV variable set in the shell is overriding phpunit.xml.
Run: php artisan config:clear, and unset any such shell variable.
```
If you see that, do what it says. On a checkout with no `.env`,
`php artisan test` also prints a `file_get_contents(...\.env)` warning per
test; that is Dotenv probing for the file and is harmless (plain
`vendor/bin/phpunit` doesn't show it).

It also sets `BROADCAST_CONNECTION=null`, so tests never need Reverb running.
Broadcast tests use `Event::fake()`; the channel-authorization tests switch to
the `reverb` driver in the test itself, which signs `/broadcasting/auth`
replies locally without contacting a server.

Smoke test:
```bash
curl http://127.0.0.1:8000/
# {"Laravel":"11.37.0"}
```

See [`API.md`](API.md) for the endpoints and [`AUTH.md`](AUTH.md) for how a
frontend authenticates against this server.

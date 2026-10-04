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

# start the API on localhost:8000, where the SPA expects it
php artisan serve --host=localhost
```

`--host=localhost` matters on Windows: without it each request waits ~0.2 s
before it even connects (see [Local speed](#local-speed), which also covers
switching on PHP's opcache and debugbar off, and serving through Apache
for requests in parallel).

For live table updates, two more processes run next to it, each in its own
terminal (see [Realtime](#realtime-reverb) below):
```bash
php artisan reverb:start            # websocket server, ws://localhost:8080
php artisan queue:work --sleep=0.1  # sends the queued broadcasts to Reverb
```
The API works without them: seat changes still succeed, the broadcasts just
wait in the `jobs` table until a worker runs.

To free the seats of players who closed the tab, and mark players away (and
forfeit their side's set) mid-set, also run the scheduler (see
[Scheduler](#scheduler-idle-seats) below):
```bash
php artisan schedule:work           # tables:release-idle-seats every minute, tables:check-away every 10 s
```

**After pulling migration changes, rebuild the DB:**
```bash
php artisan migrate:fresh --seed
```
Migrations are still edited in place rather than added as new files (most
recently `43-set-forfeit`, which added `table_seats.away_since`). A plain `php artisan migrate`
sees nothing new and leaves the old schema. `migrate:fresh` drops every
table, so local data is lost.

### Seeded data

Outside `APP_ENV=production` the seeders play every table through the game
services (`TableSeatService`, `BoardSelectionService` — every player of a
full table presses Start, and asks for the next board of a set —,
`AuctionService`, `CardPlayService`,
`ClaimService`), so every seat, call, card and claim is one the API would have accepted and
`GET /tables/{table}/playing` reads sensible turns, tricks and scores. You
get one table per phase, named after it:

| Table | State |
|---|---|
| `Your call` | full, mid-auction, and it is the admin's turn to call |
| `Bidding` | full, mid-auction on the second board of its set (the first was played out) |
| `Playing` | full, contract reached, between 1 and 51 cards played |
| `Finished` | all 13 tricks played and scored, waiting for the next board |
| `Claimed` | stopped mid-play by declarer's claim of a random share of the remaining tricks, which both defenders accepted; scored, waiting for the next board |
| `Passed out` | four passes, finished with score 0 |
| `Set over` | a whole set of four boards played out: the fourth is on show, the set's result is up (`GET /sets/{set}`), and the next set waits for everyone's Start |
| `Waiting for players` | 2 players, no board yet |

Log in as the admin (`email@abc.com` / `pass`) to act at `Your call`.
The other seeded users all have the password `password`. Calls, cards and
dealt boards are random, so each `migrate:fresh --seed` gives a different
game. The seeders don't queue any broadcasts.

No seeded table has robots. To play against robots, log in and create one
with `POST /tables` and `{"robots": true}` (the admin must leave `Your call`
first), then press Start (`POST /tables/{table}/start`): the robots are
always ready, so that deals the board. Robots move only through the queue, so
**`queue:work` must be running** or they never act (see
[Robots](#robots)).

The seeded players never send heartbeats, so if `schedule:work` is running
their seats are freed like anyone else's (after 5 minutes, or 15 at a table
mid-board), which deletes their tables. Re-seed to get them back. The
admin's heartbeat only keeps the admin's own seat.

Check migration status without applying anything:
```bash
php artisan migrate:status
```

Server listens on `http://localhost:8000` (matches `APP_URL` in `.env`).

Config of note (`.env`):
- `DB_DATABASE=bridge`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_USERNAME=root`, no password
- `FRONTEND_URL` — the SPA's origin, used for CORS `allowed_origins` and
  Sanctum's stateful domains; not in `.env.example`, so it defaults to
  `http://localhost:3000`
- `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`
- `DEBUGBAR_ENABLED` — unset, debugbar is on with `APP_ENV=local`; `false`
  turns it off and makes each request cheaper (see [Local speed](#local-speed))
- `PHP_CLI_SERVER_WORKERS` — `artisan serve` workers, Linux/macOS only; 1
  on Windows, where the built-in server can't fork
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
| API | `php artisan serve --host=localhost` (or Apache, see [Local speed](#local-speed)) | HTTP, including `POST /broadcasting/auth` |
| Websocket server | `php artisan reverb:start` (add `--debug` to log every frame) | holds the players' connections on port 8080 |
| Queue worker | `php artisan queue:work --sleep=0.1` | broadcasts are queued jobs; the worker sends them to Reverb. It also runs the robots' moves (`DriveRobots`) |

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
| `REVERB_MAX_REQUEST_SIZE` | `64000` (also the default in `config/reverb.php`) | the largest HTTP request, headers included, Reverb accepts from the app — each broadcast is one. Reverb's own default is 10000 |

**Message size.** Every broadcast is kept under 10 KB, hosted Pusher's limit
per event, so the move to Pusher stays a `.env` change (how, and the test
that holds it there: [`API.md`](API.md#message-size)). Reverb's request limit
is raised anyway, so that a payload that outgrows the budget still reaches
local players while it is fixed, instead of freezing their screens: a
broadcast over the limit fails in the queue with `Pusher error: Payload too
large.` and nobody sees that state until they reload. Hosted Pusher's 10 KB
can't be raised. Note that it is `REVERB_MAX_REQUEST_SIZE` (the server's
HTTP side, which the app's broadcasts arrive on) that matters here, not
`REVERB_APP_MAX_MESSAGE_SIZE`, which only limits what a websocket client
sends. Every failed broadcast is logged at `error` (`storage/logs`) with
its event, table and size — check there, and `php artisan queue:failed`,
when a table stops updating. `reverb:start` reads it at start: restart it
after changing it.

An existing `.env` from before this branch needs those keys added (copy them
from `.env.example`) or broadcasts fail in the queue worker.

## Scheduler (idle seats)

`routes/console.php` schedules two commands every minute and one every ten
seconds:

- `tables:release-idle-seats` (every minute) frees the seat of every human
  player who has sent no heartbeat or playing request for too long, exactly
  as if they had left (see [`API.md`](API.md#post-tablestableheartbeat)) —
  only at tables that aren't in the middle of a set. Robots and admins are
  never idle: an admin's seat is only ever taken by the admin or another
  admin.
- `tables:check-away` (every **ten seconds**) handles the middle of a set
  (see [`API.md`](API.md#away-mid-set-and-the-forfeit)): it marks a human
  with no sign of life for `BRIDGE_AWAY_SECONDS` as away, and once they have
  been away `BRIDGE_SET_FORFEIT_MINUTES` their side forfeits the set and
  their seat is freed. It also frees the seats of players still away when a
  set ends. An admin is shown away but never forfeits nor loses the seat.
  A minute would be too coarse for a three-minute deadline, so it
  runs every few seconds; `schedule:work` (and `schedule:run`, which keeps
  running through the minute) runs such sub-minute tasks.
- `tables:delete-unattended` deletes every table that only robots have kept
  for longer than `BRIDGE_UNATTENDED_TABLE_MINUTES` since its last human
  left (see [`API.md`](API.md#tables)).

Locally run `php artisan schedule:work` in its own terminal; in production
use one cron entry, `* * * * * php /path/to/artisan schedule:run`. Run either
once by hand with `php artisan tables:release-idle-seats`,
`php artisan tables:check-away` or `php artisan tables:delete-unattended`.
Without the scheduler nothing is freed: a player who vanished keeps their
seat until somebody kicks them, nobody is ever marked away or forfeits a
set (a Leave mid-set holds the seat for good), and an unattended table
stays until somebody kicks its robots.

| Key | Default | Meaning |
|---|---|---|
| `BRIDGE_IDLE_SEAT_MINUTES` | `5` | minutes without a sign of life before a seat is freed, at a table not in the middle of a set (`config/bridge.php`) |
| `BRIDGE_AWAY_SECONDS` | `60` | mid-set, seconds without a sign of life before a player is marked away and their seat held |
| `BRIDGE_SET_FORFEIT_MINUTES` | `3` | mid-set, minutes away (since the last sign of life, or the Leave) before the player's side forfeits the set |
| `BRIDGE_UNATTENDED_TABLE_MINUTES` | `10` | minutes a table with only robots left is kept before it is deleted |

### Sets

| Key | Default | Meaning |
|---|---|---|
| `BRIDGE_SET_SIZE` | `4` | boards in a set (`config/bridge.php`): Start deals the first, Next the rest, and after the last it takes everyone's Start again. A set keeps the size it opened with |

## Robots

Robot players (see [`ROBOTS.md`](ROBOTS.md)) make their moves in the **queue
worker**: after every `PlayingUpdated`, the queued listener
`App\Listeners\DriveRobots` waits `BRIDGE_ROBOT_DELAY_SECONDS` and makes
one robot move if one is due, which sends the next `PlayingUpdated`, and so
on. So locally:

- `php artisan queue:work --sleep=0.1` must be running, or robots never
  move (their jobs wait in the `jobs` table and all run once a worker
  starts);
- restart it after changing robot code, like any PHP change;
- a robot move that throws something unexpected fails its job and the table
  waits on that robot: `php artisan queue:failed` shows it, and
  `php artisan queue:retry all` runs it again. A move the rules refuse (the
  table changed in between) is dropped quietly and logged at `info`, since the
  change that caused it sends its own event.

| Key | Default | Meaning |
|---|---|---|
| `BRIDGE_ROBOT_DELAY_SECONDS` | `1` | how long each robot waits before its move, so a human can follow the play. `0` makes them instant |

Robots are ordinary `users` rows (`is_robot`), made the first time they are
needed; `migrate:fresh` wipes them with everything else.

Like `queue:work`, restart `schedule:work` after changing PHP code.

## Local speed

Measured for `39-fast-table-entry` on the XAMPP stack: Windows 11, PHP
8.2.12 (ZTS, xdebug loaded in `debug` mode), MariaDB 10.4, a seeded DB, curl
on the same machine. Medians of 30 requests (5 for `POST /tables`), in
seconds. `GET /api/user` stands for a light logged-in request.
When this was measured, `POST /tables` with `robots: true` was the whole way
into a table: it dealt the board and returned the caller's game state as
`playing`. Since `40-start-board` it only seats the robots, and the
creator's `POST /tables/{table}/start` deals and returns `playing` instead,
so the dealing part of the time below has moved to that request. "4 at once" is the wall
time of four `GET /api/user` sent together, as the SPA does when it opens a
table.

| Server | opcache | debugbar | host the client uses | `GET /api/user` | `GET /bids` | `POST /tables` robots | 4 at once |
|---|---|---|---|---|---|---|---|
| `artisan serve` | off | on | `localhost` | 0.34 | 0.35 | 1.08 | 0.74 |
| `artisan serve` | off | on | `127.0.0.1` | 0.13 | 0.14 | 0.86 | 0.54 |
| `artisan serve` | off | off | `127.0.0.1` | 0.11 | 0.12 | 0.71 | 0.48 |
| `artisan serve` | on | on | `localhost` | 0.26 | 0.27 | 0.94 | 0.43 |
| `artisan serve` | on | off | `localhost` | 0.25 | 0.26 | 0.82 | 0.38 |
| `artisan serve` | on | on | `127.0.0.1` | 0.045 | 0.059 | 0.74 | 0.23 |
| `artisan serve` | on | off | `127.0.0.1` | **0.035** | **0.044** | **0.60** | 0.19 |
| Apache vhost | on | on | `localhost` | 0.092 | 0.106 | 1.02 | 0.17 |
| Apache vhost | on | off | `localhost` | 0.070 | 0.094 | 0.77 | **0.13** |

The first row is how XAMPP comes out of the box, and what the frontend
measured in [bridge#55](https://github.com/bulbulica2/bridge/issues/55):
~0.35 s a request. Entering a robot table there cost `POST /tables` plus
`GET .../playing`, 1.08 + 0.36 = 1.44 s. With the fixes below it is one
0.60 s request. What matters, biggest first:

1. **Opcache.** XAMPP's `php.ini` ships with it off, so every request
   compiles the whole framework again: ~0.08 s each. Switch it on in
   `C:\xampp\php\php.ini` and restart Apache / `artisan serve`:
   ```ini
   zend_extension=opcache        ; uncomment (line ~964)
   [opcache]
   opcache.enable=1              ; uncomment
   opcache.revalidate_freq=0     ; check files on every request, so edits show at once
   ```
   `opcache.enable_cli` stays off, so artisan commands and the tests don't
   use it.
2. **The host `artisan serve` listens on.** By default it listens on
   `127.0.0.1` only. On Windows `localhost` resolves to `::1` first, and a
   refused IPv6 connection takes ~0.2 s before the client falls back to
   IPv4. The built-in server closes the connection after every response,
   so every request pays it again. The SPA calls `http://localhost:8000`,
   and has to: the session cookie only goes along if the API's host is the
   SPA's (`localhost`). So run `php artisan serve --host=localhost`, which
   listens on `::1`. That measured 0.05 s for `GET /bids`, the same as the
   `127.0.0.1` rows. `http://127.0.0.1:8000` then stops answering. Apache
   listens on both, so it doesn't have this problem.
3. **Debugbar.** It's on by default with `APP_ENV=local`. Set
   `DEBUGBAR_ENABLED=false` in `.env` to turn it off. It costs
   ~0.01–0.02 s on a light request and ~0.14 s on `POST /tables` with
   robots, which runs many queries. Before `39-fast-table-entry` the key was
   ignored (`AppServiceProvider` force-enabled it).
4. **One request at a time.** `PHP_CLI_SERVER_WORKERS` gives
   `artisan serve` worker processes, but only on Linux and macOS: PHP's
   built-in server needs `fork()` for them, which Windows doesn't have, so
   there the key does nothing. On Windows,
   `artisan serve` answers requests strictly one after another, so 4 at
   once take 4× as long. For requests in parallel, serve `public/` through
   XAMPP's Apache instead (below). Each Apache request is slower than
   `artisan serve`'s (0.07 vs 0.035 s), but four at once take 0.13 s
   rather than 0.19 s. With opcache off, Apache was noisy: 0.13–0.24 s a
   request.

`POST /tables` with robots was the slowest request (~0.6 s at best): it
seated three robots and dealt a board, all in the DB. The deal is now the
Start's (not re-measured).

### Serving through Apache (Windows, requests in parallel)

Add a vhost on its own port to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`
and restart Apache from the XAMPP Control Panel:
```apache
Listen 8001
<VirtualHost *:8001>
    DocumentRoot "C:/xampp/htdocs/bridge_backend/public"
    <Directory "C:/xampp/htdocs/bridge_backend/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
`AllowOverride All` lets `public/.htaccess` route every path to
`index.php` (XAMPP loads `mod_rewrite`). Apache reads `.env` and the PHP
files on every request, so code changes need no restart; a `php.ini`
change does. `reverb:start`, `queue:work` and `schedule:work` still run as
before. Apache uses the same `php.ini` as the CLI, so opcache (above)
applies to it too.

Then make these agree with the new port (`8001` here, or `8000` with
`artisan serve` stopped, which spares the frontend any change):
- **`APP_URL=http://localhost:8001`**: links the app builds use it, and
  Sanctum adds its host to the stateful domains.
- **The frontend's `VITE_API_BASE_URL`**: `http://localhost:8001`.
  Keep `localhost`, not `127.0.0.1`. The SPA runs on `localhost:3000`, and
  the session and `XSRF-TOKEN` cookies only travel with its requests when
  the API is on the same host (cookies ignore the port).
- **`FRONTEND_URL` / `SANCTUM_STATEFUL_DOMAINS`** name the SPA's origin
  (`http://localhost:3000` / `localhost:3000`), not the API's, so they
  don't change with the API's port. They do if the SPA moves.
- **`SESSION_DOMAIN`** stays `null`: the cookie belongs to the API's host,
  `localhost`, which the SPA shares. Setting it to `127.0.0.1` or another
  host breaks login.

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

### Coverage

```bash
composer coverage
```

(`php scripts/coverage.php run`) runs the suite with line coverage of
`app/` and writes:
- `coverage/html/index.html` — the browsable report, per directory and file,
  with each line marked run or not;
- `coverage/clover.xml` — the same as XML, which `scripts/coverage.php` then
  sums up per directory (Markdown on the console) before failing if line
  coverage is under the **95% floor**, as CI does.

`coverage/` is gitignored. It needs a coverage driver, and picks one by
itself:
- **pcov** (recommended): about 2 minutes for the whole suite. On XAMPP
  (PHP 8.2, thread safe, x64) get `php_pcov.dll` from
  `https://downloads.php.net/~windows/pecl/releases/pcov/` (the
  `8.2-ts-vs16-x64` zip), copy it to `C:\xampp\php\ext` and add
  `extension=pcov` to `php.ini`. When pcov is loaded the script runs PHPUnit
  with Xdebug off, so the two don't add up.
- **Xdebug**, which XAMPP's PHP ships, otherwise (run in coverage mode
  whatever `xdebug.mode` says): much slower — well over ten minutes, and
  gigabytes of memory.

Without either, PHPUnit warns "No code coverage driver available" and the
script finds no report. `php scripts/coverage.php coverage/clover.xml` only
summarises a report that is already there.

Every issue and PR keeps line coverage at or above 95%. A change that would
drop it adds tests; the floor never goes down.

### CI

GitHub Actions (`.github/workflows/tests.yml`) runs three checks on every PR
against `main`, on every push to one, and on every push to `main`: **`pint`**
(`vendor/bin/pint --test`), **`tests`** (the full suite through
`vendor/bin/phpunit`) and **`coverage`** (the suite again, with pcov,
through `scripts/coverage.php` as above). All run on Ubuntu with PHP 8.2,
`pint` and `tests` with no coverage driver at all (`coverage: none`), install
from `composer.lock`, and use exactly the `phpunit.xml` setup above — no
MySQL, no `.env`, no Reverb. The workflow must never set `DB_*` or
`APP_ENV`, or the `TestCase` guard fails every test. A new push cancels the
PR's older run; the Actions tab can also re-run it by hand. Results show as
checks on the PR and under the repo's
[Actions](https://github.com/bulbulica2/bridge_backend/actions/workflows/tests.yml)
tab. `coverage` puts its per-directory table in the run's summary page and
uploads the HTML report as the **`coverage-html`** artifact (download it
from the run page and open `index.html`); it fails when line coverage of
`app/` is under 95%. Linux is case-sensitive where Windows isn't, so a class
or file name whose case doesn't match can pass locally and fail there.

It also sets `QUEUE_CONNECTION=sync`, so the robots' queued moves run inside
the request that made them due (one after another, not nested) and a test
sees a board with robots advance to the human's turn straight away; the
`robot_delay_seconds` delay doesn't apply there.

It also sets `BROADCAST_CONNECTION=null`, so tests never need Reverb running.
Broadcast tests use `Event::fake()`; the channel-authorization tests switch to
the `reverb` driver in the test itself, which signs `/broadcasting/auth`
replies locally without contacting a server.

Smoke test:
```bash
curl http://localhost:8000/
# {"Laravel":"11.37.0"}
```

See [`API.md`](API.md) for the endpoints and [`AUTH.md`](AUTH.md) for how a
frontend authenticates against this server.

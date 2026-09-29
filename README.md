# bridge_backend

Laravel 11 / PHP 8.2 API for an online **contract bridge** app: four players
sit at a table, bid in an auction and play 13 tricks on a pre-dealt board,
with results compared across tables (duplicate bridge). Sanctum session
auth, MySQL, and live updates over Laravel Reverb. The client is the
[`bridge`](https://github.com/bulbulica2/bridge) Ionic Vue SPA.

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
php artisan test
```

Everything else is in [`docs/`](docs/README.md):

- [`GAME-RULES.md`](docs/GAME-RULES.md) — the rules of bridge and how the
  backend maps them
- [`RUNNING.md`](docs/RUNNING.md) — local setup, Reverb, queue, scheduler,
  seeded data, tests
- [`AUTH.md`](docs/AUTH.md) — session/cookie auth, CORS, channel auth
- [`API.md`](docs/API.md) — every route and websocket event
- [`DATA-MODEL.md`](docs/DATA-MODEL.md) — tables, fields and relations
- [`ARCHITECTURE.md`](docs/ARCHITECTURE.md) — how the code is organised

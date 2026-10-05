# Bridge Backend Docs

Documentation for the `bridge_backend` API (Laravel 11 / PHP 8.2): how to
run it, how a client talks to it, and how the code is organised.

Source repo: https://github.com/bulbulica2/bridge_backend
Local path: `C:\xampp\htdocs\bridge_backend`

Files here:
- [`GAME-RULES.md`](GAME-RULES.md) — what contract bridge is, its rules
  and scoring, and how they map onto this backend (read this first if you're
  new to bridge)
- [`RUNNING.md`](RUNNING.md) — how to start the app locally, including the
  Reverb websocket server, queue worker and scheduler (and why Reverb),
  installing the DDS double dummy solver, and how fast it runs locally: opcache, debugbar, `localhost` and Apache for
  parallel requests, with measurements
- [`AUTH.md`](AUTH.md) — how login/registration/session auth works
- [`API.md`](API.md) — every route, method, params, response shape, and the
  websocket channels/events
- [`DATA-MODEL.md`](DATA-MODEL.md) — entities, fields, relationships, and the
  bridge domain enums
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — for backend developers: the layers,
  the game services, robots, policies, events, the scheduler and queue,
  seeding, and the gotchas
- [`ROBOTS.md`](ROBOTS.md) — the robot players: when they act, their
  SAYC-style bidding system rule by rule, how they plan, play, signal,
  claim and answer claims, and how they compare with the first robots

The frontend (the `bridge` repo, an Ionic Vue 3 SPA) documents itself in
https://github.com/bulbulica2/bridge/tree/main/docs and links here for
endpoint shapes and the cookie/CORS flow rather than repeating them.

> These describe the code in the same commit, not aspirations. They live in
> the repo so that a change to a route, a request/response shape, a model or
> a rule updates the matching doc **in the same PR**, where it gets reviewed
> with the code. If you change the code without touching the docs, check
> whether they need an edit before you open the PR.

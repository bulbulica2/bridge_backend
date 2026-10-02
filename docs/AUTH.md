# Authentication

See [`API.md`](API.md) for game endpoints and [`DATA-MODEL.md`](DATA-MODEL.md)
for the `User` model's fields.

Uses **Laravel Sanctum in SPA (cookie/session) mode**, not bearer tokens — the
backend expects a browser-based frontend running on one of the stateful
domains, not a mobile/plain HTTP client sending an `Authorization: Bearer`
header.

- Stateful domains (get cookie-based auth automatically): `localhost`,
  `localhost:3000`, `127.0.0.1`, `127.0.0.1:3000`, `127.0.0.1:8000`, `::1`,
  plus whatever `APP_URL` resolves to (`config/sanctum.php`).
- CORS (`config/cors.php`): `allowed_origins` = `FRONTEND_URL` (default
  `http://localhost:3000`), `supports_credentials = true`. The frontend must
  send requests with `credentials: 'include'` (fetch) / `withCredentials: true`
  (axios).

## Flow for a frontend SPA

1. `GET /sanctum/csrf-cookie` first (Sanctum's built-in route) to get the
   `XSRF-TOKEN` cookie.
2. `POST /register` or `POST /login` with the `X-XSRF-TOKEN` header set from
   that cookie. On success, Laravel sets a session cookie; subsequent
   requests are authenticated via that cookie.
3. `POST /logout` to end the session.

## Routes (`routes/auth.php`, all web/session-based)

| Method | Path | Controller | Middleware | Notes |
|---|---|---|---|---|
| POST | `/register` | `Auth\RegisteredUserController@store` | `guest` | body `{name, username, email, password, password_confirmation}`; creates user, logs them in, 204 |
| POST | `/login` | `Auth\AuthenticatedSessionController@store` | `guest` | validated via `Auth\LoginRequest`; never logs in a robot |
| POST | `/forgot-password` | `Auth\PasswordResetLinkController@store` | `guest` | sends reset link email |
| POST | `/reset-password` | `Auth\NewPasswordController@store` | `guest` | |
| GET | `/verify-email/{id}/{hash}` | `Auth\VerifyEmailController` | `auth`, `signed`, `throttle:6,1` | signed link from email |
| POST | `/email/verification-notification` | `Auth\EmailVerificationNotificationController@store` | `auth`, `throttle:6,1` | resend verification email |
| POST | `/logout` | `Auth\AuthenticatedSessionController@destroy` | `auth` | |

These are the stock Laravel Breeze auth controllers, with two
customizations:

- `/register` also takes `username` (required, string, max 255, unique, and
  not starting with `robot-`, case-insensitively — that prefix is the robot
  pool's), because `users.username` is NOT NULL. Without it, registration
  failed with a 500 until `7-fix-database`.
- **Robots can't log in.** `LoginRequest::authenticate()` adds
  `is_robot = false` to the credentials, so a robot user (`users.is_robot`)
  gets the ordinary "these credentials do not match" 422 even with its
  password. Robots never need a session: their moves are made server-side by
  `App\Services\RobotService`. Their emails are `@robots.invalid`, so a
  password-reset link for one reaches nobody (and would still not let it log
  in).

See [`DATA-MODEL.md`](DATA-MODEL.md#user-users) for the `User` fields.

## Authenticated API check

`GET /api/user` (in `routes/api.php`) behind `auth:sanctum` — returns the
current user, useful to check "am I logged in" from a frontend. It is the one
place a user's own `email` is returned; other players only ever see the public
profile (see [`API.md`](API.md#users)).

`PATCH /api/user` (same file, same `auth:sanctum`) lets that user edit their
own `name` and `description`. From the SPA it needs the `X-XSRF-TOKEN` header
like any other state-changing request.

## Websocket channels (Reverb)

Live updates come over Reverb (see [`RUNNING.md`](RUNNING.md#realtime-reverb)
and [`API.md`](API.md#realtime-websocket)). Connecting the socket needs no
login; **subscribing to a private channel does**, and it reuses the same
Sanctum cookie session as every other request — no token of its own.

1. The client opens `ws://<REVERB_HOST>:<REVERB_PORT>/app/<REVERB_APP_KEY>`
   and Reverb replies with a `socket_id`.
2. To subscribe to `private-table.{id}`, the client calls
   **`POST /broadcasting/auth`** on the API with
   `{socket_id, channel_name: "private-table.{id}"}`, sending the session
   cookie (`withCredentials`) and the `X-XSRF-TOKEN` header like any other
   POST. The route is registered by `bootstrap/app.php` (`channels:`) in the
   `web` middleware group, so it reads the session directly.
3. Laravel runs the channel's callback in `routes/channels.php`. Allowed:
   **200** `{"auth": "<key>:<signature>"}`, signed with `REVERB_APP_SECRET`.
   Refused, or a guest: **403**.
4. The client sends that `auth` string to Reverb with its subscribe message;
   Reverb checks the signature and admits it.

Laravel Echo does steps 1–4 itself. Its default authorizer doesn't send the
XSRF header, so point it at axios (which does):

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import axios from 'axios';

window.Pusher = Pusher;
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;

const echo = new Echo({
  broadcaster: 'reverb',
  key: 'bridge-local-key',          // REVERB_APP_KEY — public
  wsHost: 'localhost',              // REVERB_HOST
  wsPort: 8080,                     // REVERB_PORT
  forceTLS: false,                  // true with REVERB_SCHEME=https
  enabledTransports: ['ws', 'wss'],
  authorizer: (channel) => ({
    authorize: (socketId, callback) => {
      axios.post('http://127.0.0.1:8000/broadcasting/auth', {
        socket_id: socketId,
        channel_name: channel.name,
      })
        .then((response) => callback(null, response.data))
        .catch((error) => callback(error));
    },
  }),
});

echo.private(`table.${tableId}`)
  .listen('TableUpdated', (e) => render(e.table))
  .listen('PlayingUpdated', (e) => renderPlaying(e.playing));

echo.private(`App.Models.User.${myUserId}`)
  .listen('HandDealt', (e) => renderHand(e.hand));
```

Channel rules (`routes/channels.php`):

| Channel | Who may subscribe |
|---|---|
| `private-table.{id}` | users **seated at that table** right now. Seated elsewhere, seated nowhere, or a guest → 403 |
| `private-App.Models.User.{id}` | only user `{id}` themselves (the Laravel default). Carries `HandDealt` |

**Authorization is checked once, at subscribe time.** A player who leaves or
is kicked stays subscribed until their socket closes — Reverb has no way to
revoke it. So the table channel may only carry what anybody may see (the
table itself is visible to every logged-in user through `GET /tables`).
Anything private to one player — their hand (`HandDealt`) — goes on that
player's own `App.Models.User.{id}` channel, never on the table channel;
`PlayingUpdated` on the table channel carries only the public part of the
game state.

## Authorization

Policies live in `app/Policies/` and are auto-discovered by name
(`TablePolicy` ↔ `App\Models\Table`).

- **`TablePolicy::manage(User, Table)`** decides who runs a table:
  `is_admin || moderated_by === user`. A table has one role, its moderator,
  so it allows:
  - its current `moderated_by` user. This starts as the creator and moves on
    when that moderator leaves, to the **human** seated there longest (a
    robot never manages a table; the creator gets no preference). With only
    robots left the table is *unattended* and `moderated_by` is null, so
    only admins manage it until a human sits down and takes the role;
  - any user with `is_admin` (cast to boolean), even an admin who isn't
    seated there. Admins may also kick the moderator: the override exists to
    stop cheating (one person on several accounts, collusion).

  `created_by` grants nothing. It is kept as a record (it is what the
  3-active-tables limit counts), and a creator who left and came back is a
  plain player.

  It gates `POST /tables/{table}/seats/users` (seat another user) through
  `AddUserToSeatRequest::authorize()`, `POST /tables/{table}/seats/robots`
  (seat a robot) through `AddRobotToSeatRequest::authorize()`, and kicks
  through `kick` below. A failure is a **403** in Laravel's default
  `{message}` shape, returned before the body is validated.
  See [`API.md`](API.md#post-tablestableseatsusers),
  [`API.md`](API.md#post-tablestableseatsrobots) and
  [`API.md`](API.md#delete-tablestableseatsuser).

  Clients don't re-implement it: every HTTP table payload carries
  `can_manage`, this policy evaluated for the caller (`TableResource`), so an
  admin who isn't the moderator also gets `true`. The `TableUpdated`
  broadcast leaves `can_manage` out (it has no single viewer); a client keeps
  its last value and refetches `GET /tables/{table}` when `moderated_by`
  changes. `GET /api/user` also returns the caller's own `is_admin`
  (read-only), hidden from every other view of a user. See
  [`API.md`](API.md#tables).
- **`TablePolicy::kick(User, Table, User $target)`** decides who may take
  `$target`'s seat away (`DELETE /tables/{table}/seats/{user}`, through
  `RemoveUserFromSeatRequest::authorize()`): the target themselves (a quit),
  anyone who passes `manage` (a kick), and — at an **unattended** table
  (`unattended_since` set, only robots left) — **any** logged-in user when
  the target is a robot, since nobody manages that table. While a human sits
  at the table, only its manager may kick a robot.
- **`TablePolicy::play(User, Table)`** is true for players seated at the
  table right now — the same audience as the `private-table.{id}` channel. It
  gates `GET /tables/{table}/playing` (checked in `PlayingController`); anyone
  else gets a **403** in Laravel's default `{message}` shape. See
  [`API.md`](API.md#get-tablestableplaying).
- Taking your own seat, or giving it up, is self-service and has no policy
  check — including through `DELETE /tables/{table}/seats/{user}` when
  `{user}` is the caller, which is a quit rather than a kick.

## Known gaps
- `User.is_admin` has no endpoint to set it; it is only set in the database
  (the seeded `email@abc.com` admin, or `UserFactory::isAdmin()` in tests).
- Registration rules are in `Auth/RegisteredUserController`: `name` required
  max 255; `username` required, max 255, unique, not `robot-…`; `email` required, lowercase,
  valid, max 255, unique; `password` confirmed, Breeze `Password::defaults()`.
  Validation errors come back as Laravel's standard 422 JSON, not the
  `{status, message, data}` shape. Login still uses `email`, not `username`.

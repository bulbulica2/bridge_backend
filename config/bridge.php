<?php

return [

  /*
  |--------------------------------------------------------------------------
  | Idle Seats
  |--------------------------------------------------------------------------
  |
  | How many minutes a player may go without a sign of life (a heartbeat or
  | a playing request, which update `table_seats.last_seen_at`) before
  | `tables:release-idle-seats` frees their seat. Only outside a set: in the
  | middle of one, the away rule below takes over.
  |
  */

  'idle_seat_minutes' => (int) env('BRIDGE_IDLE_SEAT_MINUTES', 5),

  /*
  |--------------------------------------------------------------------------
  | Away mid-set, and the turn clock
  |--------------------------------------------------------------------------
  |
  | In the middle of a set, a human with no sign of life for this many
  | seconds is marked away (`table_seats.away_since`), and their seat is
  | held. Pressing Leave mid-set marks them away at once.
  |
  | An away seat is kept this many seconds from `away_since`
  | (`table_seats.replace_at`), whoever's turn it is: then a robot takes it
  | for the rest of the set, unless they came back first. Every away
  | seat's runs at once. Never an admin's: the table waits for them.
  |
  | The human the board waits for (in the auction or the play; never a
  | robot or an admin) has this many seconds to call, play or act on a
  | claim, from when the board began waiting for them. Past that, a robot
  | takes their seat for the rest of the set. An away player's turn lasts
  | until their seat's `replace_at` instead. All of it is checked by
  | `tables:check-away`, scheduled every ten seconds.
  |
  */

  'away_seconds' => (int) env('BRIDGE_AWAY_SECONDS', 60),

  'away_replace_seconds' => (int) env('BRIDGE_AWAY_REPLACE_SECONDS', 120),

  'turn_seconds' => (int) env('BRIDGE_TURN_SECONDS', 60),

  /*
  |--------------------------------------------------------------------------
  | Sets
  |--------------------------------------------------------------------------
  |
  | How many boards a set has. Everyone's Start deals a set's first board,
  | and after its last one the table stops for the set's result until
  | everyone presses Start again. A set keeps the size it was opened with,
  | so changing this only affects sets opened afterwards.
  |
  | In between, a finished board stays on show for this many seconds and
  | then the set's next board is dealt by itself (the queued `DealNextBoard`
  | job, so it needs `queue:work`), or at once when every human at the
  | table has asked for it with Next.
  |
  */

  'set_size' => (int) env('BRIDGE_SET_SIZE', 4),

  'next_board_seconds' => (int) env('BRIDGE_NEXT_BOARD_SECONDS', 15),

  /*
  |--------------------------------------------------------------------------
  | Start timer
  |--------------------------------------------------------------------------
  |
  | Outside a set, once a full table waits for one human's Start only (and
  | at least one other human has pressed it), that human has this many
  | seconds to press it (`table_seats.start_deadline`), or their seat is
  | freed. The queued `ExpireStart` job does it, so it needs `queue:work`.
  |
  */

  'start_seconds' => (int) env('BRIDGE_START_SECONDS', 15),

  /*
  |--------------------------------------------------------------------------
  | Set clock
  |--------------------------------------------------------------------------
  |
  | Each human (not an admin) gets a time bank for a whole set, like a chess
  | clock: it only runs down while the board waits for them. A table picks
  | it (`tables.set_minutes`, one of `Table::SET_MINUTES`: 8, 12, 16 or 20);
  | this is what a new table gets when it names none. Running out is a turn
  | timeout: a robot takes the seat for the rest of the set (`set_time`).
  |
  */

  'set_minutes' => (int) env('BRIDGE_SET_MINUTES', 16),

  /*
  |--------------------------------------------------------------------------
  | Claims
  |--------------------------------------------------------------------------
  |
  | How many seconds the other players have to answer a claim. One still
  | pending then is rejected, as a "no" would reject it: silence means no.
  | The queued `ExpireClaim` job does it, so it needs `queue:work`.
  |
  */

  'claim_seconds' => (int) env('BRIDGE_CLAIM_SECONDS', 10),

  /*
  |--------------------------------------------------------------------------
  | Robots
  |--------------------------------------------------------------------------
  |
  | A table whose last human has left keeps its robots but is unattended:
  | `tables:delete-unattended` deletes it after this many minutes unless a
  | human sits down first.
  |
  | Robots wait this many seconds before each move (the delay on the queued
  | `DriveRobots` listener), so a human can follow the play: less when a
  | claim is waiting for their answer, so that it comes before the claim
  | expires.
  |
  */

  'unattended_table_minutes' => (int) env('BRIDGE_UNATTENDED_TABLE_MINUTES', 10),

  'robot_delay_seconds' => (int) env('BRIDGE_ROBOT_DELAY_SECONDS', 1),

  /*
  |--------------------------------------------------------------------------
  | Double dummy
  |--------------------------------------------------------------------------
  |
  | The DDS library (`libdds.so.0`, `dds.dll`) the double dummy analysis
  | runs on, through PHP's FFI extension (see RUNNING.md). Unset, nothing is
  | solved and the double dummy endpoints answer `status: unavailable`.
  | Solving happens only in `queue:work`, never in a request.
  |
  */

  'dds_library' => env('DDS_LIBRARY'),

  /*
  |--------------------------------------------------------------------------
  | DDS's memory
  |--------------------------------------------------------------------------
  |
  | Left to itself DDS sizes its transposition tables for every core of the
  | machine (about 1 GB of native memory on 20 cores, which PHP doesn't
  | count). The solver caps it at this many megabytes and threads when it
  | loads the library (DDS's `SetResources`). A board's table takes about a
  | tenth of a second, so a couple of threads is plenty. 0 lets DDS pick.
  |
  */

  'dds_memory_mb' => (int) env('BRIDGE_DDS_MEMORY_MB', 256),

  'dds_threads' => (int) env('BRIDGE_DDS_THREADS', 2),

  /*
  |--------------------------------------------------------------------------
  | Queue worker
  |--------------------------------------------------------------------------
  |
  | On, `queue:work` logs a `debug` line after every job with its PHP
  | memory (`LogJobMemory`), to find what makes it grow. A worker's stop is
  | logged either way (`LogWorkerStopping`).
  |
  */

  'log_job_memory' => (bool) env('BRIDGE_LOG_JOB_MEMORY', false),

];

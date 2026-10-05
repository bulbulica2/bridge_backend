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
  | Away mid-set, and the forfeit
  |--------------------------------------------------------------------------
  |
  | In the middle of a set, a human with no sign of life for this many
  | seconds is marked away (`table_seats.away_since`), and their seat is
  | held. Pressing Leave mid-set marks them away at once.
  |
  | Still away this many minutes after their last sign of life (or their
  | Leave), their side forfeits the set and the seat is freed. Both are
  | checked by `tables:check-away`, scheduled every ten seconds.
  |
  */

  'away_seconds' => (int) env('BRIDGE_AWAY_SECONDS', 60),

  'set_forfeit_minutes' => (int) env('BRIDGE_SET_FORFEIT_MINUTES', 3),

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

  'next_board_seconds' => (int) env('BRIDGE_NEXT_BOARD_SECONDS', 10),

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

];

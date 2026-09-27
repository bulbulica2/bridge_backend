<?php

return [

  /*
  |--------------------------------------------------------------------------
  | Idle Seats
  |--------------------------------------------------------------------------
  |
  | How many minutes a player may go without a sign of life (a heartbeat or
  | a playing request, which update `table_seats.last_seen_at`) before
  | `tables:release-idle-seats` frees their seat. A table in the middle of a
  | board gets the longer timeout: freeing a seat there abandons the board
  | for the other three, so a brief network drop shouldn't do it.
  |
  */

  'idle_seat_minutes' => (int) env('BRIDGE_IDLE_SEAT_MINUTES', 5),

  'idle_playing_seat_minutes' => (int) env('BRIDGE_IDLE_PLAYING_SEAT_MINUTES', 15),

];

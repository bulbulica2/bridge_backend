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

  /*
  |--------------------------------------------------------------------------
  | Sets
  |--------------------------------------------------------------------------
  |
  | How many boards a set has. Everyone's Start deals a set's first board,
  | Next its others, and after its last one the table stops for the set's
  | result until everyone presses Start again. A set keeps the size it was
  | opened with, so changing this only affects sets opened afterwards.
  |
  */

  'set_size' => (int) env('BRIDGE_SET_SIZE', 4),

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
  | `DriveRobots` listener), so a human can follow the play.
  |
  */

  'unattended_table_minutes' => (int) env('BRIDGE_UNATTENDED_TABLE_MINUTES', 10),

  'robot_delay_seconds' => (int) env('BRIDGE_ROBOT_DELAY_SECONDS', 1),

];

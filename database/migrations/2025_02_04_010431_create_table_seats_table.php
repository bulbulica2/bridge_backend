<?php

use App\auxiliary\Seats;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  /**
   * Run the migrations.
   */
  public function up(): void
  {
    Schema::create('table_seats', function (Blueprint $table) {
      $table->id();
      $table->foreignId('table_id')->constrained('tables')->onDelete('cascade');
      $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
      $table->enum('seat', Seats::SEATS);
      // last sign of life from the player (a heartbeat or a playing request),
      // read by tables:release-idle-seats to free the seats of players who left
      $table->timestamp('last_seen_at')->useCurrent()->index();
      // when the seat holder pressed Start (POST /tables/{table}/start); a
      // robot is ready from the moment it sits down. The board is dealt once
      // the table is full and every seat is ready, which clears the humans'
      $table->timestamp('ready_at')->nullable();
      // mid-set only: since when the player has been away, i.e. their last
      // sign of life once tables:check-away noticed a minute without one, or
      // when they pressed Leave
      $table->timestamp('away_since')->nullable();
      // the forfeit clock: set only for an away player the board is waiting
      // for (on turn), to bridge.set_forfeit_minutes after it began waiting
      // for them. Their side forfeits the set then unless they come back
      // first. Null for everyone else, admins included
      $table->timestamp('forfeit_at')->nullable();
      $table->timestamps();

      // one user per seat at a table
      $table->unique(['table_id', 'seat']);
      // a user sits at one table at a time
      $table->unique('user_id');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('table_seats');
  }
};

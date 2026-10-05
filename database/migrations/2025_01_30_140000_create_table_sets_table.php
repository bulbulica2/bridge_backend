<?php

use App\auxiliary\Seats;
use App\Models\TableSet;
use App\Models\TableSetSeat;
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
    // a set of boards (bridge.set_size, 4) the same four players play in a
    // row at a table: opened by everyone's Start, followed board to board by
    // Next, over after its last board (GAME-RULES.md §8, Sets of boards)
    Schema::create('table_sets', function (Blueprint $table) {
      $table->id();
      // null once the table has been deleted: the set outlives it, like the
      // playings in it
      $table->foreignId('table_id')->nullable()->constrained('tables')->nullOnDelete();
      // 1, 2, 3… at that table
      $table->unsignedInteger('number');
      // how many boards the set was dealt for (bridge.set_size when it opened)
      $table->unsignedTinyInteger('size');
      $table->timestamp('started_at')->useCurrent();
      // set once the set is over, however it ended
      $table->timestamp('finished_at')->nullable();
      $table->enum('ended', TableSet::ENDINGS)->nullable();
      $table->timestamps();

      $table->unique(['table_id', 'number']);
    });

    // who sat where for the whole set: the four of every playing in it
    Schema::create('table_set_seats', function (Blueprint $table) {
      $table->id();
      $table->foreignId('table_set_id')->constrained('table_sets')->onDelete('cascade');
      // who plays the seat now: a robot once its human walked out mid-set
      $table->foreignId('user_id')->constrained('users');
      $table->enum('seat', Seats::SEATS);
      // the human a robot (user_id) took over from mid-set, and why: their
      // turn clock ran out (turn_timeout, or away if they were away then),
      // they moved to another table, or were kicked while away or banned.
      // They may not sit down at the table again until the set is over
      $table->foreignId('replaced_user_id')->nullable()->constrained('users');
      $table->enum('replaced_reason', TableSetSeat::REASONS)->nullable();
      $table->timestamp('replaced_at')->nullable();
      $table->timestamps();

      $table->unique(['table_set_id', 'seat']);
      $table->unique(['table_set_id', 'user_id']);
      $table->index('user_id');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('table_set_seats');
    Schema::dropIfExists('table_sets');
  }
};

<?php

use App\auxiliary\Seats;
use App\Models\TableSet;
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
      // the side that lost the set by forfeit; nothing writes it yet (#76)
      $table->enum('forfeited_by', TableSet::SIDES)->nullable();
      $table->timestamps();

      $table->unique(['table_id', 'number']);
    });

    // who sat where for the whole set: the four of every playing in it
    Schema::create('table_set_seats', function (Blueprint $table) {
      $table->id();
      $table->foreignId('table_set_id')->constrained('table_sets')->onDelete('cascade');
      $table->foreignId('user_id')->constrained('users');
      $table->enum('seat', Seats::SEATS);
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

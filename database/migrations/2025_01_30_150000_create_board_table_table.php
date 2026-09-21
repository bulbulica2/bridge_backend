<?php

use App\auxiliary\Seats;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   */
  public function up(): void
  {
    // one playing of a board at a table
    Schema::create('board_table', function (Blueprint $table) {
      $table->id();
      $table->foreignId('board_id')->constrained('boards');
      // null once the table has been deleted: the playing outlives the table,
      // so a user's board history survives (see board_table_seats below)
      $table->foreignId('table_id')->nullable()->constrained('tables')->nullOnDelete();

      // auction result, null until the auction ends (passed out = ended with no contract)
      $table->foreignId('contract_bid_id')->nullable()->constrained('bids');
      $table->unsignedTinyInteger('doubled')->default(0); // 0 none, 1 X, 2 XX
      $table->enum('declarer_seat', Seats::SEATS)->nullable();
      $table->foreignId('declarer_id')->nullable()->constrained('users');

      $table->unsignedTinyInteger('tricks_won')->nullable(); // by declarer's side
      $table->integer('score')->nullable(); // from N-S perspective

      $table->timestamp('started_at')->useCurrent();
      $table->timestamp('auction_ended_at')->nullable();
      $table->timestamp('finished_at')->nullable();
      $table->timestamps();

      // a table never plays the same board twice
      $table->unique(['board_id', 'table_id']);
    });

    // who sat where for that playing, kept after players leave the table
    Schema::create('board_table_seats', function (Blueprint $table) {
      $table->id();
      $table->foreignId('board_table_id')->constrained('board_table')->onDelete('cascade');
      $table->foreignId('user_id')->constrained('users');
      $table->enum('seat', Seats::SEATS);
      $table->timestamps();

      $table->unique(['board_table_id', 'seat']);
      $table->unique(['board_table_id', 'user_id']);
      // board selection: has this user played a board, and in which seat
      $table->index(['user_id', 'seat']);
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('board_table_seats');
    Schema::dropIfExists('board_table');
  }
};

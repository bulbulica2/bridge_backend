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
    // one playing of a board at a table
    Schema::create('board_table', function (Blueprint $table) {
      $table->id();
      $table->foreignId('board_id')->constrained('boards');
      // null once the table has been deleted: the playing outlives the table,
      // so a user's board history survives (see board_table_seats below)
      $table->foreignId('table_id')->nullable()->constrained('tables')->nullOnDelete();
      // the set this board was dealt in, and its place there (1 to the set's
      // size); null only for playings made outside the game services
      $table->foreignId('table_set_id')->nullable()->constrained('table_sets');
      $table->unsignedTinyInteger('set_position')->nullable();

      // auction result, null until the auction ends (passed out = ended with no contract)
      $table->foreignId('contract_bid_id')->nullable()->constrained('bids');
      $table->unsignedTinyInteger('doubled')->default(0); // 0 none, 1 X, 2 XX
      $table->enum('declarer_seat', Seats::SEATS)->nullable();
      $table->foreignId('declarer_id')->nullable()->constrained('users');

      $table->unsignedTinyInteger('tricks_won')->nullable(); // by declarer's side
      $table->integer('score')->nullable(); // from N-S perspective

      // a claim (GAME-RULES.md §5): the claimer's seat, the tricks they claim
      // for their side of those still to play, and the seats that have
      // accepted so far, and when it expires (rejected, if still pending).
      // Null while none is pending; a rejected, withdrawn or expired claim
      // clears them, an accepted one keeps them, so the result can say the
      // board ended by claim
      $table->enum('claim_seat', Seats::SEATS)->nullable();
      $table->unsignedTinyInteger('claim_tricks')->nullable();
      $table->json('claim_accepted')->nullable();
      $table->timestamp('claim_expires_at')->nullable();
      // a claim that ended without being accepted (rejected, withdrawn or
      // expired) locks claims until the next card is played
      $table->boolean('claim_locked')->default(false);
      // when the board began waiting for whoever is on turn now: the deal,
      // the last call or card, or a claim cleared. Their turn clock
      // (bridge.turn_seconds) runs from here, if they have one
      // (PlayingStateService::turnDeadline())
      $table->timestamp('turn_started_at')->nullable();

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
      // once the board is finished: when this player asked for the next one
      $table->timestamp('ready_at')->nullable();
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

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
    Schema::create('auctions', function (Blueprint $table) {
      $table->id();
      // the playing (board + table) the call was made in. board_table outlives
      // its table, so the call-by-call log is deleted explicitly when the table
      // goes (Table::deleting) or the playing is abandoned; the contract that
      // came out of it stays on board_table
      $table->foreignId('board_table_id')->constrained('board_table')->cascadeOnDelete();
      $table->foreignId('user_id')->constrained('users');
      $table->foreignId('bid_id')->constrained('bids');
      $table->enum('seat', Seats::SEATS);
      // a self-alert: the bidder marked the call as conventional and may say
      // what it means (the opponents see it, partner doesn't)
      $table->boolean('alerted')->default(false);
      $table->string('explanation', 200)->nullable();
      // the opponent's seat with a question about this call still open
      $table->enum('question_seat', Seats::SEATS)->nullable();
      $table->timestamps();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('auctions');
  }
};

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

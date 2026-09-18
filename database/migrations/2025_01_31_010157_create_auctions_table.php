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
    Schema::create('auctions', function (Blueprint $table) {
      $table->id();
      $table->foreignId('board_id')->constrained('boards');
      // the call-by-call log dies with the table; the contract that came out of
      // it is saved on board_table, which outlives the table
      $table->foreignId('table_id')->constrained('tables')->cascadeOnDelete();
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

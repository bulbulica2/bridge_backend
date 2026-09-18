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
    Schema::create('cardplays', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained('users');
      // the card-by-card log dies with the table; the result it produced is
      // saved on board_table, which outlives the table
      $table->foreignId('table_id')->constrained('tables')->cascadeOnDelete();
      $table->foreignId('board_id')->constrained('boards');
      $table->foreignId('card_id')->constrained('cards');
      // the hand the card came from; declarer also plays dummy's cards
      $table->enum('seat', Seats::SEATS);
      $table->integer('round');
      $table->integer('order');
      // set on the winning card when the 4th card of the trick is played
      $table->boolean('won_trick')->default(false);
      $table->timestamps();

      // a card is played once per playing (board + table)
      $table->unique(['board_id', 'table_id', 'card_id']);
      // one card per trick position
      $table->unique(['board_id', 'table_id', 'round', 'order']);
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('cardplays');
  }
};

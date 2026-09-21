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
      // the playing (board + table) the card was played in. board_table
      // outlives its table, so the card-by-card log is deleted explicitly when
      // the table goes (Table::deleting) or the playing is abandoned; the
      // result it produced stays on board_table
      $table->foreignId('board_table_id')->constrained('board_table')->cascadeOnDelete();
      $table->foreignId('card_id')->constrained('cards');
      // the hand the card came from; declarer also plays dummy's cards
      $table->enum('seat', Seats::SEATS);
      $table->integer('round');
      $table->integer('order');
      // set on the winning card when the 4th card of the trick is played
      $table->boolean('won_trick')->default(false);
      $table->timestamps();

      // a card is played once per playing
      $table->unique(['board_table_id', 'card_id']);
      // one card per trick position
      $table->unique(['board_table_id', 'round', 'order']);
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

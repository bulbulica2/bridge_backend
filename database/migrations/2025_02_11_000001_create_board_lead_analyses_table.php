<?php

use App\auxiliary\Seats;
use App\auxiliary\Suits;
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
    // declarer's double dummy tricks after each possible opening lead, for
    // one declarer and strain on a board: shared by every playing that
    // reached that contract (the level and doubling don't change the play),
    // solved once, when the first of them finishes
    Schema::create('board_lead_analyses', function (Blueprint $table) {
      $table->id();
      $table->foreignId('board_id')->constrained('boards')->cascadeOnDelete();
      $table->enum('declarer_seat', Seats::SEATS);
      $table->enum('strain', array_keys(Suits::ALL_SUIT_NAMES));
      // [{card_id, tricks}, …]: the opening leader's 13 cards in hand order
      $table->json('leads');
      $table->timestamps();

      $table->unique(['board_id', 'declarer_seat', 'strain']);
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('board_lead_analyses');
  }
};

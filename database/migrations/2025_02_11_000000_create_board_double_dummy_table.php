<?php

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
    // a board's double dummy table (DoubleDummyService): it depends only on
    // the deal, so it is solved once per board, when it is first dealt
    Schema::create('board_double_dummy', function (Blueprint $table) {
      $table->id();
      $table->foreignId('board_id')->unique()->constrained('boards')->cascadeOnDelete();
      // {N: {C, D, H, S, NT}, E: …, S: …, W: …}: declarer's tricks
      $table->json('tricks');
      $table->timestamps();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('board_double_dummy');
  }
};

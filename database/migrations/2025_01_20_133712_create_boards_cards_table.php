<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   */
  public function up(): void
  {
    Schema::create('boards_cards', function (Blueprint $table) {
      $table->foreignId('board_id')->constrained('boards');
      $table->foreignId('card_id')->constrained('cards');
      $table->integer('position');
      $table->string('position_name');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('boards_cards');
  }
};

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
    Schema::create('board_card', function (Blueprint $table) {
      $table->foreignId('board_id')->constrained('boards');
      $table->foreignId('card_id')->constrained('cards');
      $table->string('position');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('board_card');
  }
};

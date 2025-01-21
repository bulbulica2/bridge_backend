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
    Schema::create('board_table', function (Blueprint $table) {
      $table->foreignId('board_id')->constrained('board');
      $table->foreignId('table_id')->constrained('table');
      $table->timestamp('played_at')->nullable();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('board_table');
  }
};

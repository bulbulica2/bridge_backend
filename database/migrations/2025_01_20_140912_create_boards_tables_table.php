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
    Schema::create('boards_tables', function (Blueprint $table) {
      $table->foreignId('board_id')->constrained('boards');
      $table->foreignId('table_id')->constrained('tables');
      $table->foreignId('declarer_id')->constrained('users');
      $table->integer('contract');
      $table->string('result');
      $table->integer('score');
      $table->timestamp('played_at')->nullable();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('boards_tables');
  }
};

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
    Schema::create('tables', function (Blueprint $table) {
      $table->id();
      $table->string('name');
      $table->foreignId('created_by')->nullable()->constrained('users');
      $table->foreignId('moderated_by')->nullable()->constrained('users');
      $table->foreignId('current_board_id')->nullable()->constrained('boards');
      $table->foreignId('north_id')->nullable()->constrained('users');
      $table->foreignId('east_id')->nullable()->constrained('users');
      $table->foreignId('south_id')->nullable()->constrained('users');
      $table->foreignId('west_id')->nullable()->constrained('users');
      $table->timestamps();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('tables');
  }
};

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
    Schema::create('table', function (Blueprint $table) {
      $table->id();
      $table->string('name');
      $table->integer('created_by')->nullable();
      $table->foreignId('current_board_id')->nullable()->constrained('board');
      $table->timestamps();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('table');
  }
};

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
      $table->string('name')->nullable();
      $table->foreignId('created_by')->nullable()->constrained('users');
      $table->foreignId('moderated_by')->nullable()->constrained('users');
      $table->foreignId('board_id')->nullable()->constrained('boards');
      // a table is open while closed_at is null
      $table->timestamp('closed_at')->nullable();
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

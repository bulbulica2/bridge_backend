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
      // a table lives only while someone sits at it; the last player to leave
      // deletes it, so there is no closed/archived state
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

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
    // ON HOLD!!!
    Schema::create('table_user', function (Blueprint $table) {
      $table->foreignId('user_id')->constrained();
      $table->foreignId('table_id')->constrained('tables');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('table_user');
  }
};

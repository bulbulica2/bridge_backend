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
    // people watching a table without a seat: they see what the table
    // channel carries and the public game state, never a hand
    Schema::create('table_kibitzers', function (Blueprint $table) {
      $table->id();
      $table->foreignId('table_id')->constrained('tables')->onDelete('cascade');
      $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
      // last sign of life (a heartbeat), read by tables:release-idle-seats to
      // drop kibitzers who left without saying so
      $table->timestamp('last_seen_at')->useCurrent()->index();
      $table->timestamps();

      // a user watches one table at a time
      $table->unique('user_id');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('table_kibitzers');
  }
};

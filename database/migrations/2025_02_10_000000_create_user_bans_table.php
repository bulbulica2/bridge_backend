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
    // an admin keeping a user away from the game (cheating, several accounts,
    // colluding) until `until`. Rows are never deleted: a lifted or expired
    // ban stays as history
    Schema::create('user_bans', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      // the admin who banned them; null if that admin's account is gone
      $table->foreignId('banned_by')->nullable()->constrained('users')->nullOnDelete();
      // shown to the banned user
      $table->text('reason');
      // useCurrent(), and `until` a dateTime: MySQL/MariaDB with
      // explicit_defaults_for_timestamp off give a bare NOT NULL timestamp an
      // implicit default (ON UPDATE CURRENT_TIMESTAMP, or a zero date strict
      // mode rejects), which sqlite in the tests never shows
      $table->timestamp('banned_at')->useCurrent();
      // the ban ends by itself here: nothing has to run, every check compares with now()
      $table->dateTime('until');
      // set when an admin lifts it early, or bans the user again (the new ban replaces it)
      $table->timestamp('lifted_at')->nullable();
      $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();

      $table->index(['user_id', 'until']);
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('user_bans');
  }
};

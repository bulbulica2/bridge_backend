<?php

use App\auxiliary\Seats;
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
    Schema::create('table_seats', function (Blueprint $table) {
      $table->id();
      $table->foreignId('table_id')->constrained('tables')->onDelete('cascade');
      $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
      $table->enum('seat', Seats::SEATS);
      $table->timestamps();

      // one user per seat at a table
      $table->unique(['table_id', 'seat']);
      // a user sits at one table at a time
      $table->unique('user_id');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('table_seats');
  }
};

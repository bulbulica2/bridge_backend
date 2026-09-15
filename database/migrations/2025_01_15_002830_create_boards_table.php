<?php

use App\auxiliary\Seats;
use App\auxiliary\Vulnerability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   */
  public function up(): void
  {
    Schema::create('boards', function (Blueprint $table) {
      $table->id();
      $table->unsignedInteger('number');
      $table->enum('dealer', Seats::SEATS);
      $table->enum('vulnerable', Vulnerability::VULNERABILITY_SEATS);
      $table->timestamps();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('boards');
  }
};

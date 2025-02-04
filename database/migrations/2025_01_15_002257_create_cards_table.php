<?php

use App\auxiliary\Suits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   */
  public function up(): void
  {
    Schema::create('cards', function (Blueprint $table) {
      $table->id();
      $table->enum('suit', array_keys(Suits::SUIT_NAME));
      $table->integer('rank');
      $table->string('rank_name')->nullable();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('cards');
  }
};

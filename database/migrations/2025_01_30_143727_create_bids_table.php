<?php

use App\auxiliary\Suits;
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
    Schema::create('bids', function (Blueprint $table) {
      $table->id();
      $table->string('suit');
      $table->string('suit_name');
      $table->boolean('special')->default(false);
      // a contract bid is a level plus a strain, and one call outranks another
      // on that pair. Both are null for the special calls (P, X, XX), which
      // have no rank of their own.
      $table->unsignedTinyInteger('level')->nullable();
      $table->enum('strain', array_keys(Suits::ALL_SUIT_NAMES))->nullable();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('bids');
  }
};

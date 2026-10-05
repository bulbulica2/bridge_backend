<?php

use App\auxiliary\Seats;
use App\Models\BoardMessage;
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
    Schema::create('board_messages', function (Blueprint $table) {
      $table->id();
      // the playing (board + table) the message was sent at. Kept with it,
      // after the table is gone and whether the board was finished or not
      $table->foreignId('board_table_id')->constrained('board_table')->cascadeOnDelete();
      $table->foreignId('user_id')->constrained('users');
      // the sender's seat: who may read an `opponents` message depends on it
      $table->enum('seat', Seats::SEATS);
      // `opponents`: the sender and their two opponents, never partner;
      // `table`: all four, in every phase
      $table->enum('to', BoardMessage::TO);
      // the call the message is about (its place in the auction, from 0)
      $table->unsignedSmallInteger('call_index')->nullable();
      // the card the message is about (its place in the play, from 0)
      $table->unsignedTinyInteger('card_index')->nullable();
      $table->string('body', BoardMessage::BODY_MAX);
      $table->timestamps();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('board_messages');
  }
};

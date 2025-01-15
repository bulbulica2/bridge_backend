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
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('created_by');
            $table->unsignedBigInteger('current_board_id');
            $table->foreign('current_board_id')->references('id')->on('boards');
        });

        Schema::create('users_tables', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('table_id');

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('table_id')->references('id')->on('tables');
        });

        Schema::create('table_boards', function (Blueprint $table) {
            $table->unsignedBigInteger('table_id');
            $table->unsignedBigInteger('board_id');

            $table->foreign('table_id')->references('id')->on('tables');
            $table->foreign('board_id')->references('id')->on('boards');
            $table->timestamp('played_at')->nullable();
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

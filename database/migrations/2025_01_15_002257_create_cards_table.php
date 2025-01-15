<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->integer('suit');
            $table->string('suit_name');
            $table->integer('rank');
            $table->string('rank_name')->nullable();
        });

        $suitName = array(
            1 => 'clubs',
            2 => 'diamonds',
            3 => 'hearts',
            4 => 'spades',
        );

        for ($suits = 1; $suits <= 4; $suits++) {
            for ($cards = 2; $cards <= 15; $cards++) {
                if ($cards == 11) {
                    continue;
                }

                if ($cards == 12) {
                    $rankName = 'Jack';
                } else if ($cards == 13) {
                    $rankName = 'Queen';
                } else if ($cards == 14) {
                    $rankName = 'King';
                } else if ($cards == 15) {
                    $rankName = 'Ace';
                } else {
                    $rankName = $cards;
                }
                DB::table('cards')->insert(
                    array(
                        'suit' => $suits,
                        'suit_name' => $suitName[$suits],
                        'rank' => $cards,
                        'rank_name' => $rankName,
                    )
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};

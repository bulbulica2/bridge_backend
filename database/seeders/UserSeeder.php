<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
  /**
   * Run the database seeds.
   */
  public function run(): void
  {
    $admins = array();

    $admins[] = array(
      'name' => 'bulbulica',
      'username' => 'bulbulica',
      'email' => 'email@email.com',
      'email_verified_at' => now(),
      'password' => Hash::make('pass'),
      'is_admin' => 1,
      'description' => 'standard description',
      'remember_token' => str()->random(10),
      'created_at' => now(),
      'updated_at' => now(),
    );

    DB::table('users')->insert($admins);
  }
}

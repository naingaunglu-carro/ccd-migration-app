<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class UserSeeder extends Seeder
{
    /**
     * Seed a single default user by invoking the user:create command.
     */
    public function run(): void
    {
        $email = 'admin@carro.co';

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Default user [{$email}] already exists; skipping.");

            return;
        }

        Artisan::call('user:create', [
            '--name'     => 'Admin',
            '--email'    => $email,
            '--password' => 'password',
            '--verified' => true,
        ], $this->command?->getOutput());
    }
}

<?php

namespace App\Console\Commands;

use App\Services\UserManager;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class UserCreate extends Command
{
    /**
     * @var string
     */
    protected $signature = 'user:create
                            {--name= : The user\'s name}
                            {--email= : The user\'s email address}
                            {--password= : The user\'s password}
                            {--verified : Mark the email address as verified}';

    /**
     * @var string
     */
    protected $description = 'Create a new user';

    public function handle(UserManager $users): int
    {
        $attributes = [
            'name'     => $this->option('name') ?? text('Name', required: true),
            'email'    => $this->option('email') ?? text('Email', required: true),
            'password' => $this->option('password') ?? password('Password', required: true),
        ];

        try {
            $data = $users->validate($attributes);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $user = $users->create($data, verified: (bool) $this->option('verified'));

        $this->info("User created: {$user->email} (ID {$user->getKey()})");

        return self::SUCCESS;
    }
}

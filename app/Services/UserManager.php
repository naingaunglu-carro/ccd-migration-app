<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserManager
{
    /**
     * Create a new user from already-validated attributes.
     *
     * @param  array{name: string, email: string, password: string}  $attributes
     */
    public function create(array $attributes, bool $verified = false): User
    {
        $user = User::create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'password' => $attributes['password'], // hashed by the model's cast
        ]);

        if ($verified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    /**
     * Validate the attributes for creating a user.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $attributes): array
    {
        return Validator::make($attributes, $this->rules())->validate();
    }

    /**
     * Get the validation rules for creating a user.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::default()],
        ];
    }
}

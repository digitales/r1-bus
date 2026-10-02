<?php

namespace App\Auth;

use App\Services\BusStore;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Hash;

/**
 * Signs in the one admin kept in BusStore. The email is the user id.
 */
final class BusStoreUserProvider implements UserProvider
{
    public function __construct(private BusStore $store) {}

    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->admin((string) $identifier);
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return $this->admin((string) ($credentials['email'] ?? ''));
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return Hash::check((string) ($credentials['password'] ?? ''), $user->getAuthPassword());
    }

    // There is nowhere to keep a remember token, so logins last as long as the session.
    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void {}

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void {}

    private function admin(string $email): ?Authenticatable
    {
        $admin = $this->store->admin();

        if ($admin === null || strcasecmp($admin['email'], $email) !== 0) {
            return null;
        }

        return new GenericUser([
            'id' => $admin['email'],
            'email' => $admin['email'],
            'password' => $admin['password'],
            'remember_token' => null,
        ]);
    }
}

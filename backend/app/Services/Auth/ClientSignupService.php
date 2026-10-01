<?php

namespace App\Services\Auth;

use App\Models\ClientUser;
use Illuminate\Support\Facades\Hash;

/**
 * BRD v4 "Client Marketplace" — self-serve marketplace client signup. Mirrors
 * OrganizationSignupService's shape (create + return the new account), but deliberately simpler:
 * a client belongs to no organization, so there's no Organization/OrganizationMember/Role
 * creation to wrap in a transaction here — a single `ClientUser::create()` is already atomic.
 */
class ClientSignupService
{
    public function signup(string $name, string $email, string $password, ?string $phone = null): ClientUser
    {
        return ClientUser::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'phone' => $phone,
            'status' => 'active',
        ]);
    }
}

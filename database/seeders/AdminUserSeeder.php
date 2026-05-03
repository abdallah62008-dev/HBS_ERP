<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the default Super Admin login. Idempotent — only inserts if
 * no user exists with the seeded email.
 *
 * Default credentials (CHANGE in production):
 *   email:    admin@hbs.local
 *   password: Admin@2026
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $superAdminRole = Role::where('slug', 'super-admin')->firstOrFail();

        User::firstOrCreate(
            ['email' => 'admin@hbs.local'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('Admin@2026'),
                'phone' => null,
                'role_id' => $superAdminRole->id,
                'status' => 'Active',
                'email_verified_at' => now(),
            ],
        );
    }
}

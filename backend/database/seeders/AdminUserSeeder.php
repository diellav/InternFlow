<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');

        if (blank($email) || blank($password)) {
            $this->command?->warn('Initial Admin not created: ADMIN_EMAIL and ADMIN_PASSWORD are not configured.');

            return;
        }

        $adminRole = Role::query()->where('name', 'ADMIN')->firstOrFail();
        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser !== null) {
            if ($existingUser->role_id !== $adminRole->id) {
                $this->command?->warn('Initial Admin not created: ADMIN_EMAIL already belongs to a non-Admin user.');
            }

            return;
        }

        User::query()->create([
            'role_id' => $adminRole->id,
            'first_name' => config('admin.first_name'),
            'last_name' => config('admin.last_name'),
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);
    }
}

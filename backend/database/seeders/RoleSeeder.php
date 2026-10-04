<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'STUDENT' => 'Student internship participant',
            'COMPANY_SUPERVISOR' => 'Company supervisor',
            'ACADEMIC_COORDINATOR' => 'Academic internship coordinator',
            'ADMIN' => 'System administrator',
        ];

        foreach ($roles as $name => $description) {
            Role::query()->updateOrCreate(
                ['name' => $name],
                ['description' => $description],
            );
        }
    }
}

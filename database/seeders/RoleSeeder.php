<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $adminRole = Role::firstOrCreate(['name' => 'Admin']);
        $permissions = Permission::pluck('id','id')->all();
        $adminRole->syncPermissions($permissions);

        $managerRole = Role::firstOrCreate(['name' => 'Manager']);

        $userRole = Role::firstOrCreate(['name' => 'User']);

        $coordinatorRole = Role::firstOrCreate(['name' => 'Coordinator']);
    }
}

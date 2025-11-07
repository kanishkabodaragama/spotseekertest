<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Database\Seeder;

class ManagerUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if (env('APP_ENV') == 'production') {
            $user = User::firstOrNew(
                ['email' => "manager@spotseeker.lk"],
                ['name' => "SpotSeeker Event Manager"]
            );
            $user->password = Hash::make('1cOREwBJSM0lNV7');
            $user->save();

            $role = Role::firstOrCreate(['name' => 'Manager']);

            //$permissions = Permission::pluck('id', 'id')->all();

            //$role->syncPermissions($permissions);

            $user->assignRole([$role->id]);
        } else {
            $user = User::firstOrNew(
                ['email' => "manageruat@spotseeker.lk"],
                ['name' => "SpotSeeker Event Manager"]               
            );
            $user->password = Hash::make('4hbii5k6nFhW4jk');
            $user->save();

            $role = Role::firstOrCreate(['name' => 'Manager']);

            //$permissions = Permission::pluck('id', 'id')->all();

            //$role->syncPermissions($permissions);

            $user->assignRole([$role->id]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        if(env('APP_ENV') == 'production') {
            $user = User::firstOrNew(
                ['email' => "admin@spotseeker.lk"],
                ['name' => "Spot Admin"]
            );
            $user->password = Hash::make('B4BGjnyd5ZL7Z8x');
            $user->save();    
    
            $role = Role::firstOrCreate(['name' => 'Admin']);
           
            $permissions = Permission::pluck('id','id')->all();
         
            $role->syncPermissions($permissions);
           
            $user->assignRole([$role->id]);
        } else {
            $user = User::firstOrNew(
                ['email' => "adminuat@spotseeker.lk"],
                ['name' => "Spot UAT Admin"]
            );
            $user->password = Hash::make('7CoZse0qeYxk4PD');
            $user->save();    
    
            $role = Role::firstOrCreate(['name' => 'Admin']);
           
            $permissions = Permission::pluck('id','id')->all();
         
            $role->syncPermissions($permissions);
           
            $user->assignRole([$role->id]);
        }
        

    }
}

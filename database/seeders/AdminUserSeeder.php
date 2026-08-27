<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('Minhnhat1'),
                'email_verified_at' => now(),
            ]
        );

        // 'role' không nằm trong $fillable của User — gán trực tiếp ở đây, nơi duy nhất
        // được phép tạo tài khoản admin.
        $admin->forceFill(['role' => 'admin'])->save();
        $admin->assignRole('admin');
    }
}

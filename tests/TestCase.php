<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Máy dev có thể đã có file dữ liệu IP thật (geoip:update) — test mà đọc nó thì IP công
        // khai trong test bị chặn hay không tùy máy chạy. Test cần thì tự gắn GeoIpDatabase giả.
        config(['services.geoip.database' => storage_path('framework/testing/no-geoip.mmdb')]);
    }

    protected function setUpRoles(): void
    {
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    protected function createUser(array $attributes = []): User
    {
        $this->setUpRoles();
        $user = User::factory()->create($attributes);
        $user->assignRole('user');

        return $user;
    }

    protected function createAdmin(array $attributes = []): User
    {
        $this->setUpRoles();
        $user = User::factory()->create($attributes);
        // 'role' không nằm trong $fillable của User — gán trực tiếp qua forceFill.
        $user->forceFill(['role' => 'admin'])->save();
        $user->assignRole('admin');

        return $user;
    }
}

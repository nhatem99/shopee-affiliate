<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
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
        $user = User::factory()->create(array_merge(['role' => 'admin'], $attributes));
        $user->assignRole('admin');
        return $user;
    }
}

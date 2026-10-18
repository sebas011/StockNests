<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('production reference seeding works with suppressed model events and preserves configured grants', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Permission::count())->toBe(16)
        ->and(Role::count())->toBe(9)
        ->and(Role::findByName('Super Administrator')->permissions()->count())->toBe(16);

    $role = Role::findByName('Auditor');
    $role->givePermissionTo('counts.approve');
    $this->seed(DatabaseSeeder::class);

    expect($role->fresh()->hasPermissionTo('counts.approve'))->toBeTrue()
        ->and(User::count())->toBe(0);
});

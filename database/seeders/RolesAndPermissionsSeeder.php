<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = ['dashboard.view', 'master-data.manage', 'budgets.manage', 'requests.create', 'requests.approve', 'procurement.manage', 'inventory.manage', 'counts.approve', 'reports.view', 'audit.view', 'users.manage', 'roles.manage'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        // DatabaseSeeder suppresses model events, including automatic cache invalidation.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $roles = [
            'Super Administrator' => $permissions,
            'System Administrator' => $permissions,
            'Budget Officer' => ['dashboard.view', 'budgets.manage', 'reports.view'],
            'Department Requester' => ['dashboard.view', 'requests.create'],
            'Department Head' => ['dashboard.view', 'requests.approve', 'reports.view'],
            'Fund Approver' => ['dashboard.view', 'requests.approve', 'counts.approve', 'reports.view'],
            'Procurement Officer' => ['dashboard.view', 'procurement.manage', 'reports.view'],
            'Inventory Custodian' => ['dashboard.view', 'inventory.manage', 'reports.view'],
            'Auditor' => ['dashboard.view', 'reports.view', 'audit.view'],
        ];
        foreach ($roles as $name => $grants) {
            // Never overwrite grants configured by an administrator on a later seed.
            if (! Role::where('name', $name)->where('guard_name', 'web')->exists()) {
                Role::create(['name' => $name, 'guard_name' => 'web'])->syncPermissions($grants);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

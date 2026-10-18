<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->date('effective_on')->nullable();
        });

        $grants = [
            'budget.approve' => [['Fund Approver', 'Super Administrator', 'System Administrator'], 'requests.approve'],
            'fund.approve' => [['Fund Approver', 'Super Administrator', 'System Administrator'], 'requests.approve'],
            'requests.department-approve' => [['Department Head', 'Super Administrator', 'System Administrator'], 'requests.approve'],
            'fiscal.reopen' => [['Super Administrator', 'System Administrator'], 'master-data.manage'],
        ];
        foreach ($grants as $name => [$roles, $previousPermission]) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            $permission = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
            // Upgrade existing defaults without restoring grants an administrator removed.
            $eligible = DB::table('roles')->join('role_has_permissions', 'roles.id', '=', 'role_has_permissions.role_id')
                ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->whereIn('roles.name', $roles)->where('roles.guard_name', 'web')
                ->where('permissions.name', $previousPermission)->where('permissions.guard_name', 'web')->pluck('roles.id');
            foreach ($eligible as $role) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permission grants may have been customized since the upgrade; retain them.
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropColumn('effective_on');
        });
    }
};

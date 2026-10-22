<?php

use App\Domain\OfficeWorkflow;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StockNestSeeder;

test('a fresh portable owner belongs to the office', function () {
    config(['stocknest.office_workflow' => true, 'stocknest.portable' => true]);
    $this->seed([RolesAndPermissionsSeeder::class, StockNestSeeder::class]);
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    $this->post('/setup', ['name' => 'Office Owner', 'email' => 'owner.office@example.test', 'password' => 'Strong-Office-Password123!', 'password_confirmation' => 'Strong-Office-Password123!'])
        ->assertRedirect('/dashboard');
    expect(User::sole()->department_id)->toBe(OfficeWorkflow::departmentId());
});

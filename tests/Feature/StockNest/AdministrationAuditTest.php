<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\FundingSource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StockNestSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, StockNestSeeder::class]);
    $this->owner = User::factory()->create(['department_id' => Department::firstOrFail()->id]);
    $this->owner->assignRole('Super Administrator');
    $this->owner->fundingSources()->sync(FundingSource::pluck('id'));
    $this->manager = User::factory()->create(['department_id' => $this->owner->department_id]);
    $this->manager->assignRole('System Administrator');
    $this->manager->fundingSources()->sync(FundingSource::pluck('id'));
});

function auditAccessInput(User $user): array
{
    return [
        'department_id' => $user->department_id,
        'role' => 'Department Requester',
        'fund_id' => FundingSource::firstOrFail()->id,
        'reason' => 'Approved access review',
        'idempotency_key' => (string) Str::uuid(),
    ];
}

test('access changes require is_active and cannot reactivate an account by omission', function () {
    $target = User::factory()->create(['department_id' => $this->owner->department_id, 'is_active' => false]);
    $target->assignRole('Department Requester');
    $target->fundingSources()->sync(FundingSource::pluck('id'));
    $before = AuditLog::count();

    $this->actingAs($this->owner)->post('/administration/users/'.$target->id.'/access', auditAccessInput($target))
        ->assertSessionHasErrors('is_active');

    expect($target->fresh()->is_active)->toBeFalse()->and(AuditLog::count())->toBe($before);
});

test('protected administrator roles cannot be rewritten or granted by a system administrator', function () {
    $before = Role::findByName('Super Administrator')->permissions()->pluck('name')->sort()->values()->all();
    $this->actingAs($this->manager)->post('/administration/roles', [
        'name' => 'Super Administrator', 'permissions' => ['dashboard.view'], 'reason' => 'Attempt protected role rewrite',
    ])->assertForbidden();
    expect(Role::findByName('Super Administrator')->permissions()->pluck('name')->sort()->values()->all())->toBe($before);

    $input = auditAccessInput($this->manager) + ['name' => 'Unauthorized owner', 'email' => 'unauthorized@example.test', 'password' => 'Strong-Initial-Password123!'];
    $input['role'] = 'Super Administrator';
    $this->post('/administration/users', $input)->assertForbidden();
    expect(User::where('email', 'unauthorized@example.test')->exists())->toBeFalse();
});

test('role permissions validate unknown names and accept existing permission selections', function () {
    $this->actingAs($this->owner)->post('/administration/roles', [
        'name' => 'Custom reviewer', 'permissions' => ['does.not.exist'], 'reason' => 'Configure review access',
    ])->assertSessionHasErrors('permissions.0');
    expect(Role::where('name', 'Custom reviewer')->exists())->toBeFalse();

    $this->post('/administration/roles', [
        'name' => 'Custom reviewer', 'permissions' => ['dashboard.view', 'reports.view'], 'reason' => 'Configure review access',
    ])->assertSessionHasNoErrors();
    expect(Role::findByName('Custom reviewer')->permissions()->pluck('name')->sort()->values()->all())->toBe(['dashboard.view', 'reports.view']);
});

test('admin-created users must replace their initial password before any workspace operation', function () {
    $initial = 'Strong-Initial-Password123!';
    $input = auditAccessInput($this->manager) + ['name' => 'New employee', 'email' => 'new.employee@example.test', 'password' => $initial];
    $this->actingAs($this->owner)->post('/administration/users', $input)->assertSessionHasNoErrors();
    $user = User::where('email', 'new.employee@example.test')->firstOrFail();
    expect($user->must_change_password)->toBeTrue();

    $this->actingAs($user)->get('/app/overview')->assertRedirect(route('security.edit'));
    $this->get('/app/reports/stock.csv')->assertRedirect(route('security.edit'));
    $this->post('/app/actions/request.save', ['idempotency_key' => (string) Str::uuid()])->assertRedirect(route('security.edit'));
    $this->get(route('password.confirm'))->assertOk();
    $this->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk();

    $this->from(route('security.edit'))->put(route('user-password.update'), [
        'current_password' => $initial, 'password' => $initial, 'password_confirmation' => $initial,
    ])->assertSessionHasErrors('password');
    expect($user->fresh()->must_change_password)->toBeTrue();

    $replacement = 'Different-Private-Password456!';
    $this->put(route('user-password.update'), [
        'current_password' => $initial, 'password' => $replacement, 'password_confirmation' => $replacement,
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
    expect($user->fresh()->must_change_password)->toBeFalse()->and(Hash::check($replacement, $user->fresh()->password))->toBeTrue();
    $this->get('/app/overview')->assertOk();
});

test('forgotten password reset replaces an initial password and revokes existing sessions', function () {
    $initial = 'Strong-Initial-Password123!';
    $user = User::factory()->create(['password' => $initial, 'must_change_password' => true]);
    DB::table('sessions')->insert([
        'id' => Str::random(40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
    ]);
    $reset = new ResetUserPassword;
    expect(fn () => $reset->reset($user, ['password' => $initial, 'password_confirmation' => $initial]))
        ->toThrow(ValidationException::class);
    expect($user->fresh()->must_change_password)->toBeTrue();

    $replacement = 'Different-Private-Password456!';
    $reset->reset($user, ['password' => $replacement, 'password_confirmation' => $replacement]);
    expect($user->fresh()->must_change_password)->toBeFalse()
        ->and(Hash::check($replacement, $user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

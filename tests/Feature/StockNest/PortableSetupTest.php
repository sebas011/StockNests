<?php

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\FundingSource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StockNestSeeder;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, StockNestSeeder::class]);
    config(['stocknest.portable' => true]);
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
});

/** @return array<string, string> */
function portableAdministratorInput(): array
{
    return [
        'name' => 'Local Administrator',
        'email' => '  OWNER@EXAMPLE.TEST ',
        'password' => 'Strong-Local-Password123!',
        'password_confirmation' => 'Strong-Local-Password123!',
    ];
}

test('normal installations keep portable setup unavailable and registration closed', function () {
    config(['stocknest.portable' => false]);

    $this->get('/')->assertRedirect('/login');
    $this->get('/setup')->assertNotFound();
    $this->post('/setup', portableAdministratorInput())->assertNotFound();
    $this->post('/register', portableAdministratorInput())->assertSessionHasErrors('email');
    expect(User::count())->toBe(0);
});

test('a new portable installation opens first-run setup', function () {
    $this->get('/')->assertRedirect('/setup');
    $this->get('/setup')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/portable-setup'));
});

test('portable setup rejects remote connections and forwarded loopback addresses', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.25'])
        ->withHeaders(['X-Forwarded-For' => '127.0.0.1']);

    $this->get('/setup')->assertNotFound();
    $this->post('/setup', portableAdministratorInput())->assertNotFound();
    expect(User::count())->toBe(0);
});

test('portable setup accepts the IPv6 loopback connection', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '::1'])->get('/setup')->assertOk();
});

test('portable setup rejects foreign host names on a loopback connection', function () {
    $this->get('http://untrusted.example.test/setup')->assertNotFound();
    $this->post('http://untrusted.example.test/setup', portableAdministratorInput())->assertNotFound();
    expect(User::count())->toBe(0);
});

test('portable setup validates strong confirmed passwords and account details', function () {
    $this->from('/setup')->post('/setup', [
        'name' => '', 'email' => 'invalid-email',
        'password' => 'weak', 'password_confirmation' => 'different',
    ])->assertRedirect('/setup')->assertSessionHasErrors(['name', 'email', 'password']);

    expect(User::count())->toBe(0);
});

test('portable setup enforces each password strength requirement', function (string $password) {
    $this->post('/setup', array_replace(portableAdministratorInput(), [
        'password' => $password, 'password_confirmation' => $password,
    ]))->assertSessionHasErrors('password');

    expect(User::count())->toBe(0);
})->with(['Short1!', 'longpassword123!', 'LongPasswordOnly!', 'LongPassword1234']);

test('portable setup creates one verified administrator with both funds and no password in its audit', function () {
    $input = portableAdministratorInput();
    $this->post('/setup', $input)->assertRedirect('/dashboard');

    $user = User::sole();
    $this->assertAuthenticatedAs($user);
    expect($user->email)->toBe('owner@example.test')
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check($input['password'], $user->password))->toBeTrue()
        ->and($user->hasRole('Super Administrator'))->toBeTrue()
        ->and($user->department_id)->toBe(Department::where('code', 'ADMIN')->value('id'))
        ->and($user->fundingSources()->count())->toBe(FundingSource::count());

    $audit = AuditLog::where('action', 'administrator.created')->sole();
    expect($audit->user_id)->toBe($user->id)
        ->and($audit->after_values)->toBe(['email' => 'owner@example.test', 'role' => 'Super Administrator'])
        ->and(json_encode($audit->getAttributes()))->not->toContain($input['password'], $user->password, 'password_confirmation');

    $this->get('/app/overview')->assertOk();
});

test('portable setup closes after the first account and rejects further provisioning', function () {
    $this->post('/setup', portableAdministratorInput())->assertRedirect('/dashboard');
    $this->get('/setup')->assertRedirect('/login');

    $this->post('/setup', array_replace(portableAdministratorInput(), ['email' => 'second@example.test']))
        ->assertStatus(409);
    expect(User::count())->toBe(1);
    expect(AuditLog::where('action', 'administrator.created')->count())->toBe(1);
});

test('portable setup waits for preparation instead of creating an unprotected account', function () {
    FundingSource::query()->delete();

    $this->post('/setup', portableAdministratorInput())->assertStatus(503);
    expect(User::count())->toBe(0);
});

test('portable setup throttles repeated provisioning attempts', function () {
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->post('/setup', [])->assertSessionHasErrors('email');
    }

    $this->post('/setup', portableAdministratorInput())->assertStatus(429);
    expect(User::count())->toBe(0);
});

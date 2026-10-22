<?php

namespace App\Http\Controllers;

use App\Domain\OfficeWorkflow;
use App\Http\Requests\PortableSetupRequest;
use App\Models\Department;
use App\Models\FundingSource;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class PortableSetupController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/portable-setup');
    }

    public function store(PortableSetupRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request): User {
            // Lock a seeded row so simultaneous setup requests cannot both create an owner.
            $fund = FundingSource::query()->orderBy('id')->lockForUpdate()->first();
            abort_unless($fund !== null, 503, 'StockNest preparation must finish before creating the administrator.');
            abort_if(User::query()->exists(), 409, 'Setup is already complete. Log in with your administrator account.');

            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $user->forceFill([
                'is_active' => true,
                'email_verified_at' => now(),
                'department_id' => OfficeWorkflow::enabled() ? OfficeWorkflow::departmentId() : (Department::query()->where('code', 'ADMIN')->value('id')
                    ?? Department::query()->orderBy('id')->value('id')),
            ])->save();
            $user->assignRole('Super Administrator');
            $user->fundingSources()->sync(FundingSource::query()->pluck('id'));

            DB::table('audit_logs')->insert([
                'user_id' => $user->id,
                'action' => 'administrator.created',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'after_values' => json_encode(['email' => $user->email, 'role' => 'Super Administrator'], JSON_THROW_ON_ERROR),
                'reason' => 'First-run portable administrator setup',
                'created_at' => now(),
            ]);

            return $user;
        }, 5);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Your administrator account is ready. Welcome to StockNest.');
    }
}

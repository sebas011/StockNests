<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            /* @chisel-2fa */
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            /* @end-chisel-2fa */
            /* @chisel-passkeys */
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $request->user()
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
            /* @end-chisel-passkeys */
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'mustChangePassword' => (bool) $request->user()->must_change_password,
        ];

        /* @chisel-2fa */
        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }
        /* @end-chisel-2fa */

        return Inertia::render('settings/security', $props);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $requiredChange = $request->user()->must_change_password;
        $request->user()->forceFill([
            'password' => $request->password,
            'must_change_password' => false,
            'remember_token' => Str::random(60),
        ])->save();
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '<>', $request->session()->getId())->delete();
        $request->session()->regenerate();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return $requiredChange ? to_route('dashboard') : back();
    }
}

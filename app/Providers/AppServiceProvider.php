<?php

namespace App\Providers;

use App\Support\RecordAuthenticationActivity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Event::listen(Login::class, fn (Login $event) => RecordAuthenticationActivity::record('login', $event->user->getAuthIdentifier()));
        Event::listen(Logout::class, fn (Logout $event) => RecordAuthenticationActivity::record('logout', $this->authenticationIdentifier($event->user)));
        Event::listen(Failed::class, fn (Failed $event) => RecordAuthenticationActivity::record('login_failed', $this->authenticationIdentifier($event->user)));
    }

    private function authenticationIdentifier(?Authenticatable $user): mixed
    {
        return $user?->getAuthIdentifier();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        if (app()->isProduction() && config('app.debug')) {
            throw new RuntimeException('Debug mode must be disabled in production. Set APP_DEBUG=false.');
        }

        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(12)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols()
            ->when(app()->isProduction() && ! config('stocknest.portable'), fn (Password $rule): Password => $rule->uncompromised()),
        );
    }
}

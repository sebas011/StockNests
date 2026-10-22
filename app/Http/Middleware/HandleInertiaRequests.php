<?php

namespace App\Http\Middleware;

use App\Domain\OfficeWorkflow;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email', 'email_verified_at', 'is_active', 'department_id']),
                'permissions' => $request->user()?->getAllPermissions()->pluck('name')->values() ?? [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'currency' => config('stocknest.currency'),
            'organization' => OfficeWorkflow::enabled() ? OfficeWorkflow::NAME : config('stocknest.organization'),
            'officeWorkflow' => OfficeWorkflow::enabled(),
            'timezone' => config('app.timezone'),
            'demoMode' => config('stocknest.demo'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}

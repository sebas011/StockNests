<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password && ! $request->routeIs(
            'security.edit', 'user-password.update', 'password.*', 'verification.*', 'logout',
        )) {
            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}

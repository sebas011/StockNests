<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePortableSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        // Use the actual connection, never a client-supplied forwarding header.
        abort_unless(config('stocknest.portable') && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true), 404);
        abort_unless(in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]', '::1'], true), 404);

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LaunchLockdown
{
    public function handle(Request $request, Closure $next): Response
    {
        if (static::isActive($request)) {
            return redirect()->route('home');
        }

        return $next($request);
    }

    public static function isActive(Request $request): bool
    {
        $headerName = config('lockdown.header_name');
        $headerValue = config('lockdown.header_value');

        if (empty($headerName) || empty($headerValue)) {
            return false;
        }

        return $request->header($headerName) !== $headerValue;
    }
}

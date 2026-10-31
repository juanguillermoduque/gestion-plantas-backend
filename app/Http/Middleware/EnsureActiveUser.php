<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->active) {
            return response()->json(['error' => 'La cuenta está inactiva. Solicita acceso a una persona administradora.'], 401);
        }

        return $next($request);
    }
}

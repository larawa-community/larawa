<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasskeyActionConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('passkeys.confirmed_at', 0);

        if ($confirmedAt < now()->subMinutes(10)->getTimestamp()) {
            abort(403, 'Confirm your current password before managing passkeys.');
        }

        return $next($request);
    }
}

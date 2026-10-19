<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(config('larawa.cloud_only'), 404);

        $token = $request->bearerToken() ?: $request->header('X-Worker-Token');
        $expected = (string) config('larawa.worker_token');

        if ($expected === '' || ! is_string($token) || $token === '' || ! hash_equals($expected, $token)) {
            return response()->json(['message' => 'Unauthorized worker request.'], 401);
        }

        return $next($request);
    }
}

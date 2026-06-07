<?php

namespace App\Http\Middleware;

use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function __construct(private ApiKeyService $apiKeys) {}

    public function handle(Request $request, Closure $next, string $scope = '*'): Response
    {
        $plainText = $request->bearerToken() ?: $request->header('X-API-Key');

        if (! $plainText) {
            return response()->json(['message' => 'Missing API key.'], 401);
        }

        $apiKey = $this->apiKeys->findValidKey($plainText);

        if (! $apiKey) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        if (! $apiKey->workspace || $apiKey->workspace->suspended_at) {
            return response()->json(['message' => 'Workspace is suspended.'], 403);
        }

        $allowList = $apiKey->ip_allow_list ?: [];
        if ($allowList && ! IpUtils::checkIp($request->ip(), $allowList)) {
            return response()->json(['message' => 'API key is not allowed from this IP address.'], 403);
        }

        if ($scope !== '*' && ! $apiKey->allowsScope($scope)) {
            return response()->json(['message' => 'API key scope is not allowed.'], 403);
        }

        if ($scope !== '*') {
            $this->apiKeys->markUsed($apiKey);
        }

        $request->attributes->set('apiKey', $apiKey);
        $request->attributes->set('workspace', $apiKey->workspace);

        return $next($request);
    }
}

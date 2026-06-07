<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use App\Services\AuditLogger;
use App\Support\IpAllowList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);

        return response()->json([
            'data' => $workspace->apiKeys()->latest()->paginate(50),
        ]);
    }

    public function store(Request $request, ApiKeyService $apiKeys, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(config('larawa.api_key_scopes'))],
            'ip_allow_list' => ['nullable', 'array'],
            'ip_allow_list.*' => ['string', 'max:64'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $workspace = $this->workspace($request);
        $apiKey = $request->attributes->get('apiKey');
        $scopes = array_values(array_unique($data['scopes']));
        $this->ensureScopesCanBeGranted($apiKey, $scopes);

        $ipAllowList = IpAllowList::parse($data['ip_allow_list'] ?? null);
        if ($invalidEntries = IpAllowList::invalidEntries($ipAllowList)) {
            throw ValidationException::withMessages([
                'ip_allow_list' => 'Invalid IP allow list entries: '.implode(', ', $invalidEntries).'. Use exact IP addresses or CIDR ranges.',
            ]);
        }

        [$createdKey, $plainText] = $apiKeys->create($workspace, $data['name'], $scopes, $ipAllowList, $data['expires_at'] ?? null);
        $audit->log('api.api_key.created', $workspace, apiKey: $apiKey, auditable: $createdKey, request: $request);

        return response()->json([
            'data' => $createdKey,
            'plain_text_key' => $plainText,
        ], 201);
    }

    public function update(Request $request, ApiKey $apiKey, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($apiKey->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'scopes' => ['sometimes', 'required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(config('larawa.api_key_scopes'))],
            'ip_allow_list' => ['sometimes', 'nullable', 'array'],
            'ip_allow_list.*' => ['string', 'max:64'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);
        $currentKey = $request->attributes->get('apiKey');
        $updates = [];

        if (array_key_exists('name', $data)) {
            $updates['name'] = $data['name'];
        }

        if (array_key_exists('scopes', $data)) {
            $scopes = array_values(array_unique($data['scopes']));
            $this->ensureScopesCanBeGranted($currentKey, $scopes);
            $updates['scopes'] = $scopes;
        }

        if (array_key_exists('ip_allow_list', $data)) {
            $ipAllowList = IpAllowList::parse($data['ip_allow_list']);
            if ($invalidEntries = IpAllowList::invalidEntries($ipAllowList)) {
                throw ValidationException::withMessages([
                    'ip_allow_list' => 'Invalid IP allow list entries: '.implode(', ', $invalidEntries).'. Use exact IP addresses or CIDR ranges.',
                ]);
            }
            $updates['ip_allow_list'] = $ipAllowList ?: null;
        }

        if (array_key_exists('expires_at', $data)) {
            $updates['expires_at'] = $data['expires_at'];
        }

        if ($updates !== []) {
            $apiKey->update($updates);
        }

        $audit->log('api.api_key.updated', $workspace, apiKey: $currentKey, auditable: $apiKey, metadata: ['fields' => array_keys($updates)], request: $request);

        return response()->json(['data' => $apiKey->fresh()]);
    }

    public function rotate(Request $request, ApiKey $apiKey, ApiKeyService $apiKeys, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($apiKey->workspace_id === $workspace->id, 404);

        [$rotatedKey, $plainText] = $apiKeys->rotate($apiKey);
        $audit->log('api.api_key.rotated', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $rotatedKey, request: $request);

        return response()->json([
            'message' => 'API key rotated. Copy the replacement key now; it will not be shown again.',
            'data' => $rotatedKey,
            'plain_text_key' => $plainText,
        ]);
    }

    public function destroy(Request $request, ApiKey $apiKey, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($apiKey->workspace_id === $workspace->id, 404);

        $apiKey->delete();
        $audit->log('api.api_key.deleted', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $apiKey, request: $request);

        return response()->json(['message' => 'API key revoked.']);
    }

    private function ensureScopesCanBeGranted(ApiKey $apiKey, array $scopes): void
    {
        if ($apiKey->allowsScope('*')) {
            return;
        }

        $denied = array_values(array_filter($scopes, fn ($scope) => ! $apiKey->allowsScope($scope)));

        if ($denied) {
            throw ValidationException::withMessages([
                'scopes' => 'This API key cannot grant scopes it does not already have: '.implode(', ', $denied).'.',
            ]);
        }
    }
}

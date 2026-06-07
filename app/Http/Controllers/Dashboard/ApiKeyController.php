<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use App\Services\AuditLogger;
use App\Support\IpAllowList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ApiKeyController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'api-keys.manage', $workspace);

        return view('dashboard.api-keys.index', [
            'workspace' => $workspace,
            'apiKeys' => ($this->isSiteAdmin($request) ? ApiKey::query()->with('workspace')->latest() : $workspace->apiKeys()->latest())->paginate(20),
            'plainTextKey' => session('plain_text_key'),
        ]);
    }

    public function store(Request $request, ApiKeyService $apiKeys, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(config('larawa.api_key_scopes'))],
            'ip_allow_list' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'api-keys.manage', $workspace);
        $ipAllowList = IpAllowList::parse($data['ip_allow_list'] ?? null);

        if ($invalidEntries = IpAllowList::invalidEntries($ipAllowList)) {
            throw ValidationException::withMessages([
                'ip_allow_list' => 'Invalid IP allow list entries: '.implode(', ', $invalidEntries).'. Use exact IP addresses or CIDR ranges.',
            ]);
        }

        [$apiKey, $plainText] = $apiKeys->create($workspace, $data['name'], array_values(array_unique($data['scopes'])), $ipAllowList, $data['expires_at'] ?? null);
        $audit->log('api_key.created', $workspace, $request->user(), auditable: $apiKey, request: $request);

        return redirect()->route('dashboard.api-keys.index')->with('plain_text_key', $plainText)->with('status', 'API key created. Copy it now; it will not be shown again.');
    }

    public function update(Request $request, ApiKey $apiKey, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'api-keys.manage', $apiKey->workspace);
        abort_unless($this->isSiteAdmin($request) || $apiKey->workspace_id === $workspace->id, 404);
        $workspace = $apiKey->workspace;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(config('larawa.api_key_scopes'))],
            'ip_allow_list' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $ipAllowList = IpAllowList::parse($data['ip_allow_list'] ?? null);

        if ($invalidEntries = IpAllowList::invalidEntries($ipAllowList)) {
            throw ValidationException::withMessages([
                'ip_allow_list' => 'Invalid IP allow list entries: '.implode(', ', $invalidEntries).'. Use exact IP addresses or CIDR ranges.',
            ]);
        }

        $apiKey->update([
            'name' => $data['name'],
            'scopes' => array_values(array_unique($data['scopes'])),
            'ip_allow_list' => $ipAllowList ?: null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);
        $audit->log('api_key.updated', $workspace, $request->user(), auditable: $apiKey, metadata: ['fields' => ['name', 'scopes', 'ip_allow_list', 'expires_at']], request: $request);

        return back()->with('status', 'API key updated.');
    }

    public function rotate(Request $request, ApiKey $apiKey, ApiKeyService $apiKeys, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'api-keys.manage', $apiKey->workspace);
        abort_unless($this->isSiteAdmin($request) || $apiKey->workspace_id === $workspace->id, 404);
        $workspace = $apiKey->workspace;

        [$rotatedKey, $plainText] = $apiKeys->rotate($apiKey);
        $audit->log('api_key.rotated', $workspace, $request->user(), auditable: $rotatedKey, request: $request);

        return redirect()->route('dashboard.api-keys.index')->with('plain_text_key', $plainText)->with('status', 'API key rotated. Copy the replacement key now; it will not be shown again.');
    }

    public function destroy(Request $request, ApiKey $apiKey, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'api-keys.manage', $apiKey->workspace);
        abort_unless($this->isSiteAdmin($request) || $apiKey->workspace_id === $workspace->id, 404);
        $workspace = $apiKey->workspace;

        $apiKey->delete();
        $audit->log('api_key.deleted', $workspace, $request->user(), auditable: $apiKey, request: $request);

        return back()->with('status', 'API key revoked.');
    }
}

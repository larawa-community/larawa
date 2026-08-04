<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Support\WorkspaceIds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    private const MEMBER_ROLES = ['workspace_admin', 'workspace_user'];

    public function index(Request $request): View
    {
        $request->user()->can('platform.admin') ?: abort(403);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
        ]);

        $workspaces = Workspace::query()
            ->withCount(['users', 'whatsappSessions', 'apiKeys'])
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $query->where(function ($query) use ($term) {
                    $query->where('name', 'like', "%{$term}%")
                        ->orWhere('slug', 'like', "%{$term}%");
                });
            })
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->whereNull('suspended_at'))
            ->when(($filters['status'] ?? null) === 'suspended', fn ($query) => $query->whereNotNull('suspended_at'))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('dashboard.workspaces.index', [
            'workspace' => $this->workspace($request),
            'workspaces' => $workspaces,
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, Workspace $workspace): View
    {
        $request->user()->can('platform.admin') ?: abort(403);

        return view('dashboard.workspaces.show', [
            'workspace' => $this->workspace($request),
            'managedWorkspace' => $workspace->loadCount(['users', 'whatsappSessions', 'apiKeys', 'webhooks']),
            'members' => $workspace->users()->orderBy('name')->paginate(15),
            'memberRoles' => self::MEMBER_ROLES,
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->user()->can('platform.admin') ?: abort(403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'session_types' => ['required', 'array', 'min:1'],
            'session_types.*' => ['required', 'distinct', Rule::in([WhatsappSession::TYPE_CLOUD, WhatsappSession::TYPE_WRAPPER])],
        ]);

        $workspace = Workspace::create([
            'name' => $data['name'],
            'slug' => WorkspaceIds::generate($data['name']),
            ...$this->sessionTypeAttributes($data['session_types']),
        ]);
        $audit->log('workspace.created', $workspace, $request->user(), auditable: $workspace, metadata: ['session_types' => $workspace->allowedSessionTypes()], request: $request);

        return back()->with('status', 'Workspace created.');
    }

    public function update(Request $request, Workspace $workspace, AuditLogger $audit): RedirectResponse
    {
        $request->user()->can('platform.admin') ?: abort(403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'session_types' => ['required', 'array', 'min:1'],
            'session_types.*' => ['required', 'distinct', Rule::in([WhatsappSession::TYPE_CLOUD, WhatsappSession::TYPE_WRAPPER])],
        ]);

        $workspace->update([
            'name' => $data['name'],
            ...$this->sessionTypeAttributes($data['session_types']),
        ]);
        $audit->log('workspace.updated', $workspace, $request->user(), auditable: $workspace, metadata: [
            'fields' => ['name', 'session_types'],
            'session_types' => $workspace->allowedSessionTypes(),
        ], request: $request);

        return back()->with('status', 'Workspace updated.');
    }

    public function toggleSuspension(Request $request, Workspace $workspace, AuditLogger $audit): RedirectResponse
    {
        $request->user()->can('platform.admin') ?: abort(403);
        if (! $workspace->suspended_at && $workspace->hasSiteAdmin()) {
            throw ValidationException::withMessages([
                'workspace' => 'The system admin workspace cannot be suspended.',
            ]);
        }

        $workspace->update(['suspended_at' => $workspace->suspended_at ? null : now()]);
        $audit->log($workspace->suspended_at ? 'workspace.suspended' : 'workspace.reactivated', $workspace, $request->user(), auditable: $workspace, request: $request);

        return back()->with('status', $workspace->suspended_at ? 'Workspace suspended.' : 'Workspace reactivated.');
    }

    public function assignAdmin(Request $request, Workspace $workspace, AuditLogger $audit): RedirectResponse
    {
        $request->user()->can('platform.admin') ?: abort(403);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'exists:users,email'],
            'role' => ['required', Rule::in(self::MEMBER_ROLES)],
        ]);
        $user = User::where('email', $data['email'])->firstOrFail();

        if ($user->isSiteAdmin()) {
            throw ValidationException::withMessages([
                'email' => 'Site admins must be managed from the system admin workspace.',
            ]);
        }

        $workspace->users()->syncWithoutDetaching([
            $user->id => ['role' => $data['role']],
        ]);
        $audit->log('workspace.member_assigned', $workspace, $request->user(), auditable: $workspace, metadata: ['user_id' => $user->id, 'role' => $data['role']], request: $request);

        return back()->with('status', 'Workspace member assigned.');
    }

    public function destroy(Request $request, Workspace $workspace, AuditLogger $audit): RedirectResponse
    {
        $request->user()->can('platform.admin') ?: abort(403);
        if ($workspace->hasSiteAdmin()) {
            throw ValidationException::withMessages([
                'workspace' => 'The system admin workspace cannot be deleted.',
            ]);
        }

        $audit->log('workspace.deleted', $workspace, $request->user(), auditable: $workspace, request: $request);
        $workspace->delete();

        return redirect()->route('dashboard.workspaces.index')->with('status', 'Workspace deleted.');
    }

    private function sessionTypeAttributes(array $types): array
    {
        return [
            'allows_official_cloud_api' => in_array(WhatsappSession::TYPE_CLOUD, $types, true),
            'allows_whatsapp_wrapper' => in_array(WhatsappSession::TYPE_WRAPPER, $types, true),
        ];
    }
}

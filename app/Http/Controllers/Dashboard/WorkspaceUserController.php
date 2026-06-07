<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkspaceUserController extends Controller
{
    private const ROLES = ['workspace_admin', 'workspace_user'];

    public function index(Request $request): View
    {
        $workspace = $this->authorizeWorkspace($request, 'workspace.manage');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'disabled'])],
            'role' => ['nullable', Rule::in(self::ROLES)],
        ]);

        $users = $workspace->users()
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $query->where(function ($query) use ($term) {
                    $query->where('users.name', 'like', "%{$term}%")
                        ->orWhere('users.email', 'like', "%{$term}%");
                });
            })
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->whereNull('users.disabled_at'))
            ->when(($filters['status'] ?? null) === 'disabled', fn ($query) => $query->whereNotNull('users.disabled_at'))
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->where('workspace_users.role', $role))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('dashboard.workspace-users.index', [
            'workspace' => $workspace,
            'users' => $users,
            'roles' => self::ROLES,
            'filters' => $filters,
            'createdCredentials' => session('created_workspace_user_credentials'),
        ]);
    }

    public function show(Request $request, User $user): View
    {
        $workspace = $this->authorizeWorkspace($request, 'workspace.manage');
        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);
        abort_if($user->roleForWorkspace($workspace) === 'site_admin', 403, 'Site admins must be managed by a site admin.');

        return view('dashboard.workspace-users.show', [
            'workspace' => $workspace,
            'member' => $user->load('workspaces'),
            'roles' => self::ROLES,
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->authorizeWorkspace($request, 'workspace.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);
        $password = Str::password(16);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($password),
        ]);

        $workspace->users()->syncWithoutDetaching([
            $user->id => ['role' => $data['role']],
        ]);
        $audit->log('workspace_user.created', $workspace, $request->user(), auditable: $user, metadata: ['role' => $data['role']], request: $request);

        return back()
            ->with('status', 'Workspace account created. Copy the generated credentials now; they will not be shown again.')
            ->with('created_workspace_user_credentials', [
                'login_url' => route('login'),
                'email' => $user->email,
                'password' => $password,
            ]);
    }

    public function update(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->authorizeWorkspace($request, 'workspace.manage');
        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);
        abort_if($user->roleForWorkspace($workspace) === 'site_admin', 403, 'Site admins must be managed by a site admin.');

        $data = $request->validate([
            'role' => ['required', Rule::in(self::ROLES)],
        ]);
        $this->preventRemovingLastWorkspaceAdmin($workspace, $user, $data['role']);

        $workspace->users()->updateExistingPivot($user->id, ['role' => $data['role']]);
        $audit->log('workspace_user.role_changed', $workspace, $request->user(), auditable: $user, metadata: ['role' => $data['role']], request: $request);

        return back()->with('status', 'Workspace role updated.');
    }

    public function destroy(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->authorizeWorkspace($request, 'workspace.manage');
        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);
        abort_if($user->roleForWorkspace($workspace) === 'site_admin', 403, 'Site admins must be managed by a site admin.');
        abort_if($user->is($request->user()), 422, 'You cannot remove your own workspace membership.');
        $this->preventRemovingLastWorkspaceAdmin($workspace, $user, null);

        $workspace->users()->detach($user->id);
        $audit->log('workspace_user.removed', $workspace, $request->user(), auditable: $user, request: $request);

        return back()->with('status', 'Workspace user removed.');
    }

    private function preventRemovingLastWorkspaceAdmin(Workspace $workspace, User $user, ?string $newRole): void
    {
        if ($user->roleForWorkspace($workspace) !== 'workspace_admin') {
            return;
        }

        if ($newRole === 'workspace_admin') {
            return;
        }

        $adminCount = $workspace->users()->wherePivot('role', 'workspace_admin')->count();

        abort_if($adminCount <= 1, 422, 'A workspace must keep at least one workspace admin.');
    }
}

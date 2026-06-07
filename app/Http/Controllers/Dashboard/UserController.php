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
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    private const ROLES = ['site_admin', 'workspace_admin', 'workspace_user'];

    public function index(Request $request): View
    {
        $request->user()->can('platform.admin') ?: abort(403);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'disabled'])],
            'role' => ['nullable', Rule::in(self::ROLES)],
        ]);

        $users = User::query()
            ->with('workspaces')
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $query->where(function ($query) use ($term) {
                    $query->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->whereNull('disabled_at'))
            ->when(($filters['status'] ?? null) === 'disabled', fn ($query) => $query->whereNotNull('disabled_at'))
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->whereHas('workspaces', fn ($query) => $query->where('workspace_users.role', $role)))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('dashboard.users.index', [
            'workspace' => $this->workspace($request),
            'users' => $users,
            'roles' => self::ROLES,
            'filters' => $filters,
            'createdCredentials' => session('created_user_credentials'),
        ]);
    }

    public function show(Request $request, User $user): View
    {
        $request->user()->can('platform.admin') ?: abort(403);

        return view('dashboard.users.show', [
            'workspace' => $this->workspace($request),
            'managedUser' => $user->load('workspaces'),
            'roles' => self::ROLES,
            'resetCredentials' => session('reset_user_credentials'),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->user()->can('platform.admin') ?: abort(403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'workspace' => ['nullable', 'string', 'max:120'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);
        if ($data['role'] !== 'site_admin' && empty($data['workspace_id']) && empty($data['workspace'])) {
            throw ValidationException::withMessages(['workspace' => 'Select a workspace for workspace roles.']);
        }
        $siteAdminWorkspace = $data['role'] === 'site_admin' ? $this->siteAdminWorkspace() : null;
        if ($data['role'] === 'site_admin' && ! $siteAdminWorkspace) {
            throw ValidationException::withMessages(['role' => 'A system admin workspace must exist before creating site admins.']);
        }
        $password = Str::password(16);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($password),
        ]);

        if ($data['role'] === 'site_admin') {
            $siteAdminWorkspace->users()->attach($user->id, ['role' => 'site_admin']);
        } else {
            $workspace = empty($data['workspace_id'])
                ? Workspace::query()
                    ->where('slug', $data['workspace'])
                    ->orWhere('name', $data['workspace'])
                    ->firstOrFail()
                : Workspace::findOrFail($data['workspace_id']);

            $workspace->users()->attach($user->id, ['role' => $data['role']]);
        }

        $audit->log('user.created', $this->workspace($request), $request->user(), auditable: $user, request: $request);

        return back()
            ->with('status', 'User created. Copy the generated credentials now; they will not be shown again.')
            ->with('created_user_credentials', [
                'login_url' => route('login'),
                'email' => $user->email,
                'password' => $password,
            ]);
    }

    public function update(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $request->user()->can('platform.admin') ?: abort(403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
        ]);

        $user->update($data);
        $audit->log('user.updated', $this->workspace($request), $request->user(), auditable: $user, metadata: ['fields' => ['name', 'email']], request: $request);

        return back()->with('status', 'User updated.');
    }

    public function resetPassword(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $request->user()->can('platform.admin') ?: abort(403);

        $password = Str::password(16);

        $user->update(['password' => Hash::make($password)]);
        $audit->log('user.password_reset', $this->workspace($request), $request->user(), auditable: $user, request: $request);

        return back()
            ->with('status', 'Password reset. Copy the generated credentials now; they will not be shown again.')
            ->with('reset_user_credentials', [
                'login_url' => route('login'),
                'email' => $user->email,
                'password' => $password,
            ]);
    }

    public function toggleDisabled(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $request->user()->can('platform.admin') ?: abort(403);
        abort_if($user->is($request->user()), 422, 'You cannot disable your own account.');
        if (! $user->disabled_at && $user->isInitialUser()) {
            throw ValidationException::withMessages([
                'user' => 'The initial user cannot be disabled.',
            ]);
        }

        $user->update(['disabled_at' => $user->disabled_at ? null : now()]);
        $audit->log($user->disabled_at ? 'user.disabled' : 'user.enabled', $this->workspace($request), $request->user(), auditable: $user, request: $request);

        return back()->with('status', $user->disabled_at ? 'User disabled.' : 'User enabled.');
    }

    public function assignWorkspace(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $request->user()->can('platform.admin') ?: abort(403);

        $data = $request->validate([
            'workspace_id' => ['required_without:workspace', 'nullable', 'integer', 'exists:workspaces,id'],
            'workspace' => ['required_without:workspace_id', 'nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);
        $workspace = empty($data['workspace_id'])
            ? Workspace::query()
                ->where('slug', $data['workspace'])
                ->orWhere('name', $data['workspace'])
                ->firstOrFail()
            : Workspace::findOrFail($data['workspace_id']);
        if ($data['role'] === 'site_admin' && ! $workspace->hasSiteAdmin()) {
            throw ValidationException::withMessages(['role' => 'Site admins can only be assigned to the system admin workspace.']);
        }

        $workspace->users()->syncWithoutDetaching([
            $user->id => ['role' => $data['role']],
        ]);
        $audit->log('user.workspace_assigned', $workspace, $request->user(), auditable: $user, metadata: ['role' => $data['role']], request: $request);

        return back()->with('status', 'Workspace membership assigned.');
    }

    public function removeWorkspace(Request $request, User $user, Workspace $membershipWorkspace, AuditLogger $audit): RedirectResponse
    {
        $request->user()->can('platform.admin') ?: abort(403);
        $membership = $user->workspaces()->whereKey($membershipWorkspace->id)->first();
        $membershipRole = $membership?->pivot?->role;

        abort_if($user->is($request->user()) && $membershipRole === 'site_admin', 422, 'You cannot remove your own site admin role.');
        if ($membershipWorkspace->hasSiteAdmin() && $membershipRole === 'site_admin') {
            $userSiteAdminMemberships = $user->workspaces()->wherePivot('role', 'site_admin')->count();
            $workspaceSiteAdmins = $membershipWorkspace->users()->wherePivot('role', 'site_admin')->count();

            if ($userSiteAdminMemberships <= 1 || $workspaceSiteAdmins <= 1) {
                throw ValidationException::withMessages([
                    'workspace' => 'The system admin workspace must keep its site admin memberships.',
                ]);
            }
        }

        $membershipWorkspace->users()->detach($user->id);
        $audit->log('user.workspace_removed', $membershipWorkspace, $request->user(), auditable: $user, request: $request);

        return back()->with('status', 'Workspace membership removed.');
    }

    public function destroy(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $request->user()->can('platform.admin') ?: abort(403);
        abort_if($user->is($request->user()), 422, 'You cannot delete your own account.');
        if ($user->isInitialUser()) {
            throw ValidationException::withMessages([
                'user' => 'The initial user cannot be deleted.',
            ]);
        }
        $protectedSiteAdminWorkspace = $user->workspaces()
            ->wherePivot('role', 'site_admin')
            ->get()
            ->first(fn (Workspace $workspace) => $workspace->users()->wherePivot('role', 'site_admin')->count() <= 1);

        if ($protectedSiteAdminWorkspace) {
            throw ValidationException::withMessages([
                'user' => 'The system admin workspace must keep at least one site admin.',
            ]);
        }

        $audit->log('user.deleted', $this->workspace($request), $request->user(), auditable: $user, request: $request);
        $user->delete();

        return back()->with('status', 'User deleted.');
    }

    private function siteAdminWorkspace(): ?Workspace
    {
        return Workspace::query()
            ->whereHas('users', fn ($query) => $query->where('workspace_users.role', 'site_admin'))
            ->oldest()
            ->first();
    }
}

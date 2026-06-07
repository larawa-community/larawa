<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveDashboardWorkspace
{
    public const SESSION_KEY = 'dashboard_workspace_id';

    public const INTENDED_KEY = 'dashboard_workspace_intended_url';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 403);

        $selectedWorkspace = $this->selectedWorkspace($request);
        if ($selectedWorkspace) {
            $request->attributes->set('workspace', $selectedWorkspace);

            return $next($request);
        }

        $memberships = $user->workspaces()
            ->whereNull('workspaces.suspended_at')
            ->orderBy('workspaces.name')
            ->get();

        if ($memberships->count() === 1) {
            $workspace = $memberships->first();
            $request->session()->put(self::SESSION_KEY, $workspace->id);
            $request->attributes->set('workspace', $workspace);

            return $next($request);
        }

        if ($memberships->count() > 1) {
            if ($request->isMethod('GET')) {
                $request->session()->put(self::INTENDED_KEY, $request->fullUrl());
            }

            return redirect()->route('dashboard.workspace.select');
        }

        $workspace = $user->currentWorkspace();
        if ($workspace) {
            $request->session()->put(self::SESSION_KEY, $workspace->id);
            $request->attributes->set('workspace', $workspace);

            return $next($request);
        }

        abort(403, 'No workspace is available for this request.');
    }

    private function selectedWorkspace(Request $request): ?Workspace
    {
        $workspaceId = $request->session()->get(self::SESSION_KEY);

        if (! $workspaceId) {
            return null;
        }

        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace || ! $request->user()->canAccessWorkspace($workspace)) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        if ($workspace->suspended_at && ! $request->user()->isSiteAdmin()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $workspace;
    }
}

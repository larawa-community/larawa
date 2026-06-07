<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

abstract class Controller
{
    protected function workspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace') ?: $request->user()?->currentWorkspace();

        abort_unless($workspace, 403, 'No workspace is available for this request.');
        abort_if($workspace->suspended_at && ! $request->user()?->isSiteAdmin(), 403, 'This workspace is suspended.');

        return $workspace;
    }

    protected function authorizeWorkspace(Request $request, string $ability, ?Workspace $workspace = null): Workspace
    {
        $workspace ??= $this->workspace($request);
        Gate::forUser($request->user())->authorize($ability, $workspace);

        return $workspace;
    }

    protected function visibleWorkspaces(Request $request)
    {
        if ($request->user()?->isSiteAdmin()) {
            return Workspace::query();
        }

        return $request->user()->workspaces()->whereNull('workspaces.suspended_at');
    }

    protected function isSiteAdmin(Request $request): bool
    {
        return (bool) $request->user()?->isSiteAdmin();
    }
}

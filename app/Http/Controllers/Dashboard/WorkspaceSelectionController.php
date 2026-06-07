<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDashboardWorkspace;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WorkspaceSelectionController extends Controller
{
    public function show(Request $request): View
    {
        return view('dashboard.workspaces.select', [
            'workspace' => $request->attributes->get('workspace'),
            'workspaces' => $this->selectableWorkspaces($request)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'integer'],
        ]);

        $workspace = $this->selectableWorkspaces($request)
            ->whereKey($data['workspace_id'])
            ->first();

        if (! $workspace) {
            throw ValidationException::withMessages([
                'workspace_id' => __('dashboard.workspace_select.invalid'),
            ]);
        }

        $request->session()->put(ResolveDashboardWorkspace::SESSION_KEY, $workspace->id);
        $intended = $request->session()->pull(ResolveDashboardWorkspace::INTENDED_KEY);

        return redirect()->to($intended ?: route('dashboard'));
    }

    private function selectableWorkspaces(Request $request)
    {
        if ($request->user()->isSiteAdmin()) {
            return Workspace::query()->orderBy('name');
        }

        return $request->user()
            ->workspaces()
            ->whereNull('workspaces.suspended_at')
            ->orderBy('workspaces.name');
    }
}

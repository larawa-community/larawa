<x-layouts.app :workspace="$workspace" title="Workspaces">
    <section class="rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold">Workspaces</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $workspaces->total() }} workspace{{ $workspaces->total() === 1 ? '' : 's' }}</p>
                </div>
                <button type="button" data-modal-open="create-workspace" class="rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1eb858]">Create Workspace</button>
            </div>
            <form method="GET" action="{{ route('dashboard.workspaces.index') }}" class="mt-4 grid gap-3 md:grid-cols-[1fr_180px_auto_auto]">
                <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name or Workspace ID" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                <select name="status" class="rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                    <option value="">Any status</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                    <option value="suspended" @selected(($filters['status'] ?? '') === 'suspended')>Suspended</option>
                </select>
                <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Filter</button>
                <a href="{{ route('dashboard.workspaces.index') }}" class="rounded-md border border-slate-300 px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear</a>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-5 py-3">Workspace</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Users</th>
                        <th class="px-5 py-3">Sessions</th>
                        <th class="px-5 py-3">Keys</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($workspaces as $managedWorkspace)
                        <tr>
                            <td class="px-5 py-4">
                                <div class="font-medium">{{ $managedWorkspace->name }}</div>
                                <div class="mt-1 text-xs text-slate-500">Workspace ID: {{ $managedWorkspace->slug }}</div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $managedWorkspace->suspended_at ? 'bg-amber-100 text-amber-800' : 'bg-[#25d366]/10 text-[#128c42]' }}">
                                    {{ $managedWorkspace->suspended_at ? 'Suspended' : 'Active' }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-slate-600">{{ $managedWorkspace->users_count }}</td>
                            <td class="px-5 py-4 text-slate-600">{{ $managedWorkspace->whatsapp_sessions_count }}</td>
                            <td class="px-5 py-4 text-slate-600">{{ $managedWorkspace->api_keys_count }}</td>
                            <td class="px-5 py-4 text-right"><a href="{{ route('dashboard.workspaces.show', $managedWorkspace) }}" class="font-semibold text-[#128c42]">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-8 text-slate-500">No workspaces match the current filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-4">{{ $workspaces->links() }}</div>
    </section>

    <div data-modal="create-workspace" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4">
        <section class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
            <div class="flex items-center justify-between gap-4">
                <h2 class="font-semibold">Create Workspace</h2>
                <button type="button" data-modal-close class="rounded-md border border-slate-300 px-3 py-1 text-sm font-semibold text-slate-600 hover:bg-slate-50">Close</button>
            </div>
            <form method="POST" action="{{ route('dashboard.workspaces.store') }}" class="mt-5 space-y-4">
                @csrf
                <label class="block"><span class="mb-1 block text-sm font-medium">Name</span><input name="name" value="{{ old('name') }}" required class="w-full rounded-md border border-slate-300 px-3 py-2"></label>
                <fieldset class="rounded-md border border-slate-200 p-4">
                    <legend class="px-1 text-sm font-semibold text-slate-700">Allowed session types</legend>
                    <div class="mt-2 space-y-3 text-sm">
                        <label class="flex items-start gap-3">
                            <input type="checkbox" name="session_types[]" value="official_cloud_api" @checked(in_array('official_cloud_api', old('session_types', ['official_cloud_api']), true)) class="mt-0.5 rounded border-slate-300 text-[#128c42] focus:ring-[#25d366]">
                            <span><span class="block font-medium">Official Cloud API</span><span class="mt-0.5 block text-xs text-slate-500">Meta-hosted WhatsApp Business Platform sessions.</span></span>
                        </label>
                        <label class="flex items-start gap-3">
                            <input type="checkbox" name="session_types[]" value="whatsapp_wrapper" @checked(in_array('whatsapp_wrapper', old('session_types', []), true)) class="mt-0.5 rounded border-slate-300 text-[#128c42] focus:ring-[#25d366]">
                            <span><span class="block font-medium">WhatsApp Wrapper</span><span class="mt-0.5 block text-xs text-slate-500">QR-linked sessions served by the local worker.</span></span>
                        </label>
                    </div>
                    <p class="mt-3 text-xs text-slate-500">Select at least one session type.</p>
                </fieldset>
                <button class="rounded-md bg-[#25d366] px-4 py-2 font-semibold text-white hover:bg-[#1eb858]">Create</button>
            </form>
        </section>
    </div>
</x-layouts.app>

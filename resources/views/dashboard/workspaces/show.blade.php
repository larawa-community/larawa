<x-layouts.app :workspace="$workspace" :title="$managedWorkspace->name">
    @php
        $isSystemAdminWorkspace = $managedWorkspace->hasSiteAdmin();
    @endphp

    <div class="mb-4">
        <a href="{{ route('dashboard.workspaces.index') }}" class="text-sm font-semibold text-[#128c42]">Back to workspaces</a>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_380px]">
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">{{ $managedWorkspace->name }}</h2>
                    <p class="mt-1 text-sm text-slate-500">Workspace ID: {{ $managedWorkspace->slug }}</p>
                </div>
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $managedWorkspace->suspended_at ? 'bg-amber-100 text-amber-800' : 'bg-[#25d366]/10 text-[#128c42]' }}">
                    {{ $managedWorkspace->suspended_at ? 'Suspended' : 'Active' }}
                </span>
            </div>
            @if ($isSystemAdminWorkspace)
                <div class="mt-4 rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-medium text-sky-800">System admin workspace</div>
            @endif

            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-4">
                <div class="rounded-md bg-slate-50 p-4"><dt class="text-slate-500">Users</dt><dd class="mt-1 text-xl font-semibold">{{ $managedWorkspace->users_count }}</dd></div>
                <div class="rounded-md bg-slate-50 p-4"><dt class="text-slate-500">Sessions</dt><dd class="mt-1 text-xl font-semibold">{{ $managedWorkspace->whatsapp_sessions_count }}</dd></div>
                <div class="rounded-md bg-slate-50 p-4"><dt class="text-slate-500">API Keys</dt><dd class="mt-1 text-xl font-semibold">{{ $managedWorkspace->api_keys_count }}</dd></div>
                <div class="rounded-md bg-slate-50 p-4"><dt class="text-slate-500">Webhooks</dt><dd class="mt-1 text-xl font-semibold">{{ $managedWorkspace->webhooks_count }}</dd></div>
            </dl>

            <form method="POST" action="{{ route('dashboard.workspaces.update', $managedWorkspace) }}" class="mt-6 grid gap-4 rounded-md bg-slate-50 p-4">
                @csrf @method('PATCH')
                <label class="block"><span class="mb-1 block text-sm font-medium">Name</span><input name="name" value="{{ old('name', $managedWorkspace->name) }}" required class="w-full rounded-md border border-slate-300 px-3 py-2"></label>
                <div><button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Save Changes</button></div>
            </form>

            <section class="mt-6 rounded-lg border border-slate-200">
                <div class="border-b border-slate-200 px-5 py-4 font-semibold">Members</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">User</th><th class="px-5 py-3">Role</th><th class="px-5 py-3">Joined</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($members as $member)
                                <tr>
                                    <td class="px-5 py-4"><div class="font-medium">{{ $member->name }}</div><div class="mt-1 text-xs text-slate-500">{{ $member->email }}</div></td>
                                    <td class="px-5 py-4"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ $member->pivot->role }}</span></td>
                                    <td class="px-5 py-4 text-slate-600">{{ $member->pivot->created_at?->diffForHumans() ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-5 py-8 text-slate-500">No members yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-4">{{ $members->links() }}</div>
            </section>
        </section>

        <aside class="space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="font-semibold">Add Workspace Member</h2>
                <form method="POST" action="{{ route('dashboard.workspaces.admins.store', $managedWorkspace) }}" class="mt-5 space-y-4">
                    @csrf
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">Email</span>
                        <input name="email" value="{{ old('email') }}" required type="email" placeholder="user@example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        @error('email')
                            <span class="mt-1 block text-xs font-semibold text-red-700">{{ $message }}</span>
                        @enderror
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">Workspace role</span>
                        <select name="role" required class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($memberRoles as $role)
                                <option value="{{ $role }}" @selected(old('role', 'workspace_user') === $role)>{{ $role }}</option>
                            @endforeach
                        </select>
                        @error('role')
                            <span class="mt-1 block text-xs font-semibold text-red-700">{{ $message }}</span>
                        @enderror
                    </label>
                    <button class="rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1eb858]">Add</button>
                </form>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="font-semibold">Workspace Actions</h2>
                @if ($isSystemAdminWorkspace && ! $managedWorkspace->suspended_at)
                    <div class="mt-5 rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-600">This workspace stores site admin access and cannot be suspended or deleted.</div>
                @else
                    <div class="mt-5 flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('dashboard.workspaces.suspension', $managedWorkspace) }}">
                            @csrf @method('PATCH')
                            <button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50">{{ $managedWorkspace->suspended_at ? 'Reactivate' : 'Suspend' }}</button>
                        </form>
                        @unless ($isSystemAdminWorkspace)
                            <form method="POST" action="{{ route('dashboard.workspaces.destroy', $managedWorkspace) }}" onsubmit="return confirm('Delete this workspace? Data will be soft deleted where supported.')">
                                @csrf @method('DELETE')
                                <button class="rounded-md border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Delete</button>
                            </form>
                        @endunless
                    </div>
                @endif
            </section>
        </aside>
    </div>
</x-layouts.app>

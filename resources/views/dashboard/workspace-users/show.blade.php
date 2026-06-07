<x-layouts.app :workspace="$workspace" :title="$member->name">
    <div class="mb-4">
        <a href="{{ route('dashboard.workspace-users.index') }}" class="text-sm font-semibold text-[#128c42]">Back to members</a>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_380px]">
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">{{ $member->name }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $member->email }}</p>
                </div>
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $member->disabled_at ? 'bg-red-100 text-red-700' : 'bg-[#25d366]/10 text-[#128c42]' }}">{{ $member->disabled_at ? 'Disabled' : 'Active' }}</span>
            </div>

            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-3">
                <div class="rounded-md bg-slate-50 p-4"><dt class="text-slate-500">Workspace</dt><dd class="mt-1 font-semibold">{{ $workspace->name }}</dd></div>
                <div class="rounded-md bg-slate-50 p-4"><dt class="text-slate-500">Role</dt><dd class="mt-1 font-semibold">{{ $member->roleForWorkspace($workspace) }}</dd></div>
                <div class="rounded-md bg-slate-50 p-4"><dt class="text-slate-500">Joined</dt><dd class="mt-1 font-semibold">{{ $member->workspaces->firstWhere('id', $workspace->id)?->pivot?->created_at?->diffForHumans() ?: '-' }}</dd></div>
            </dl>
        </section>

        <aside class="space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="font-semibold">Role</h2>
                <form method="POST" action="{{ route('dashboard.workspace-users.update', $member) }}" class="mt-5 space-y-4">
                    @csrf @method('PATCH')
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">Workspace role</span>
                        <select name="role" required class="w-full rounded-md border border-slate-300 px-3 py-2">
                            @foreach ($roles as $role)
                                <option value="{{ $role }}" @selected($member->roleForWorkspace($workspace) === $role)>{{ $role }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Save Role</button>
                </form>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="font-semibold">Membership Actions</h2>
                @if ($member->is(auth()->user()))
                    <p class="mt-3 text-sm text-slate-500">Another workspace admin must remove this account from the workspace.</p>
                @else
                    <form method="POST" action="{{ route('dashboard.workspace-users.destroy', $member) }}" class="mt-5" onsubmit="return confirm('Remove this user from the workspace?')">
                        @csrf @method('DELETE')
                        <button class="rounded-md border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Remove From Workspace</button>
                    </form>
                @endif
            </section>
        </aside>
    </div>
</x-layouts.app>

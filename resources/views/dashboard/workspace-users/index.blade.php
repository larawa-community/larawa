<x-layouts.app :workspace="$workspace" title="Workspace Users">
    @php
        $csvHref = null;
        if ($createdCredentials) {
            $csv = "login_url,email(username),password\n";
            foreach (['login_url', 'email', 'password'] as $field) {
                $value = str_replace('"', '""', $createdCredentials[$field] ?? '');
                $csv .= '"'.$value.'"'.($field === 'password' ? "\n" : ',');
            }
            $csvHref = 'data:text/csv;charset=utf-8,'.rawurlencode($csv);
            $csvFilename = 'larawa-workspace-user-credentials-'.now()->format('Ymd-His').'.csv';
        }
    @endphp

    @if ($createdCredentials)
        <div data-modal="created-workspace-user-credentials" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
            <section class="w-full max-w-lg rounded-lg bg-white p-5 shadow-xl">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="font-semibold">Account Created</h2>
                    <button type="button" data-modal-close class="rounded-md border border-slate-300 px-3 py-1 text-sm font-semibold text-slate-600 hover:bg-slate-50">Close</button>
                </div>
                <div class="mt-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Copy or download these credentials now. The password will not be shown again.</div>
                <dl class="mt-5 space-y-3 text-sm">
                    <div><dt class="font-medium text-slate-600">Login URL</dt><dd class="mt-1 break-all rounded-md bg-slate-50 px-3 py-2">{{ $createdCredentials['login_url'] }}</dd></div>
                    <div><dt class="font-medium text-slate-600">Email</dt><dd class="mt-1 break-all rounded-md bg-slate-50 px-3 py-2">{{ $createdCredentials['email'] }}</dd></div>
                    <div><dt class="font-medium text-slate-600">Password</dt><dd class="mt-1 break-all rounded-md bg-slate-50 px-3 py-2 font-mono">{{ $createdCredentials['password'] }}</dd></div>
                </dl>
                <div class="mt-5 flex flex-wrap gap-3">
                    <a href="{{ $csvHref }}" download="{{ $csvFilename }}" class="rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1eb858]">Download CSV</a>
                    <button type="button" data-modal-close class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Done</button>
                </div>
            </section>
        </div>
    @endif

    <section class="rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold">Members</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $users->total() }} member{{ $users->total() === 1 ? '' : 's' }}</p>
                </div>
                <button type="button" data-modal-open="create-account" class="rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1eb858]">Create Account</button>
            </div>
            <form method="GET" action="{{ route('dashboard.workspace-users.index') }}" class="mt-4 grid gap-3 md:grid-cols-[1fr_160px_190px_auto_auto]">
                <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name or email" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                <select name="status" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Any status</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                    <option value="disabled" @selected(($filters['status'] ?? '') === 'disabled')>Disabled</option>
                </select>
                <select name="role" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Any role</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role }}" @selected(($filters['role'] ?? '') === $role)>{{ $role }}</option>
                    @endforeach
                </select>
                <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Filter</button>
                <a href="{{ route('dashboard.workspace-users.index') }}" class="rounded-md border border-slate-300 px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear</a>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr><th class="px-5 py-3">Member</th><th class="px-5 py-3">Role</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Joined</th><th class="px-5 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($users as $member)
                        <tr>
                            <td class="px-5 py-4"><div class="font-medium">{{ $member->name }}</div><div class="mt-1 text-xs text-slate-500">{{ $member->email }}</div></td>
                            <td class="px-5 py-4"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ $member->pivot->role }}</span></td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $member->disabled_at ? 'bg-red-100 text-red-700' : 'bg-[#25d366]/10 text-[#128c42]' }}">{{ $member->disabled_at ? 'Disabled' : 'Active' }}</span>
                            </td>
                            <td class="px-5 py-4 text-slate-600">{{ $member->pivot->created_at?->diffForHumans() ?: '-' }}</td>
                            <td class="px-5 py-4">
                                @if ($member->pivot->role === 'site_admin')
                                    <div class="text-right text-xs font-semibold text-slate-400">Site admin</div>
                                @else
                                    <div class="flex flex-wrap items-center justify-end gap-3">
                                        <a href="{{ route('dashboard.workspace-users.show', $member) }}" class="font-semibold text-[#128c42]">Open</a>
                                        @if ($member->is(auth()->user()))
                                            <span class="text-xs font-semibold text-slate-400">Current user</span>
                                        @else
                                            <form method="POST" action="{{ route('dashboard.workspace-users.destroy', $member) }}" onsubmit="return confirm('Remove this user from the workspace?')">
                                                @csrf @method('DELETE')
                                                <button class="font-semibold text-red-700 hover:text-red-800">Remove</button>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-8 text-slate-500">No members match the current filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-4">{{ $users->links() }}</div>
    </section>

    <div data-modal="create-account" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4">
        <section class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
            <div class="flex items-center justify-between gap-4">
                <h2 class="font-semibold">Create Account</h2>
                <button type="button" data-modal-close class="rounded-md border border-slate-300 px-3 py-1 text-sm font-semibold text-slate-600 hover:bg-slate-50">Close</button>
            </div>
            <form method="POST" action="{{ route('dashboard.workspace-users.store') }}" class="mt-5 space-y-4">
                @csrf
                <label class="block"><span class="mb-1 block text-sm font-medium">Name</span><input name="name" value="{{ old('name') }}" required class="w-full rounded-md border border-slate-300 px-3 py-2"></label>
                <label class="block"><span class="mb-1 block text-sm font-medium">Email</span><input name="email" value="{{ old('email') }}" required type="email" class="w-full rounded-md border border-slate-300 px-3 py-2"></label>
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Role</span>
                    <select name="role" required class="w-full rounded-md border border-slate-300 px-3 py-2">
                        @foreach ($roles as $role)
                            <option value="{{ $role }}" @selected($role === 'workspace_user')>{{ $role }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="rounded-md bg-[#25d366] px-4 py-2 font-semibold text-white hover:bg-[#1eb858]">Create</button>
            </form>
        </section>
    </div>
</x-layouts.app>

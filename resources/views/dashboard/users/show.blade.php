<x-layouts.app :workspace="$workspace" :title="$managedUser->name">
    @php
        $csvHref = null;
        $csvFilename = null;
        $isInitialUser = $managedUser->isInitialUser();
        if ($resetCredentials) {
            $csv = "login_url,email(username),password\n";
            foreach (['login_url', 'email', 'password'] as $field) {
                $value = str_replace('"', '""', $resetCredentials[$field] ?? '');
                $csv .= '"'.$value.'"'.($field === 'password' ? "\n" : ',');
            }
            $csvHref = 'data:text/csv;charset=utf-8,'.rawurlencode($csv);
            $csvFilename = 'larawa-user-credentials-'.now()->format('Ymd-His').'.csv';
        }
    @endphp

    @if ($resetCredentials)
        <div data-modal="reset-user-credentials" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
            <section class="w-full max-w-lg rounded-lg bg-white p-5 shadow-xl">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="font-semibold">Password Reset</h2>
                    <button type="button" data-modal-close class="rounded-md border border-slate-300 px-3 py-1 text-sm font-semibold text-slate-600 hover:bg-slate-50">Close</button>
                </div>
                <div class="mt-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Copy or download these credentials now. The password will not be shown again.</div>
                <dl class="mt-5 space-y-3 text-sm">
                    <div><dt class="font-medium text-slate-600">Login URL</dt><dd class="mt-1 break-all rounded-md bg-slate-50 px-3 py-2">{{ $resetCredentials['login_url'] }}</dd></div>
                    <div><dt class="font-medium text-slate-600">Email</dt><dd class="mt-1 break-all rounded-md bg-slate-50 px-3 py-2">{{ $resetCredentials['email'] }}</dd></div>
                    <div><dt class="font-medium text-slate-600">Password</dt><dd class="mt-1 break-all rounded-md bg-slate-50 px-3 py-2 font-mono">{{ $resetCredentials['password'] }}</dd></div>
                </dl>
                <div class="mt-5 flex flex-wrap gap-3">
                    <a href="{{ $csvHref }}" download="{{ $csvFilename }}" class="rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1eb858]">Download CSV</a>
                    <button type="button" data-modal-close class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Done</button>
                </div>
            </section>
        </div>
    @endif

    <div class="mb-4">
        <a href="{{ route('dashboard.users.index') }}" class="text-sm font-semibold text-[#128c42]">Back to users</a>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_380px]">
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">{{ $managedUser->name }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $managedUser->email }}</p>
                </div>
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $managedUser->disabled_at ? 'bg-red-100 text-red-700' : 'bg-[#25d366]/10 text-[#128c42]' }}">{{ $managedUser->disabled_at ? 'Disabled' : 'Active' }}</span>
            </div>
            @if ($isInitialUser)
                <div class="mt-4 rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-medium text-sky-800">Initial system user</div>
            @endif

            <form method="POST" action="{{ route('dashboard.users.update', $managedUser) }}" class="mt-6 grid gap-4 rounded-md bg-slate-50 p-4 md:grid-cols-2">
                @csrf @method('PATCH')
                <label class="block"><span class="mb-1 block text-sm font-medium">Name</span><input name="name" value="{{ old('name', $managedUser->name) }}" required class="w-full rounded-md border border-slate-300 px-3 py-2"></label>
                <label class="block"><span class="mb-1 block text-sm font-medium">Email</span><input name="email" value="{{ old('email', $managedUser->email) }}" required type="email" class="w-full rounded-md border border-slate-300 px-3 py-2"></label>
                <div class="md:col-span-2"><button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Save Changes</button></div>
            </form>

            <section class="mt-6 rounded-lg border border-slate-200">
                <div class="border-b border-slate-200 px-5 py-4 font-semibold">Workspace Memberships</div>
                <div class="divide-y divide-slate-100">
                    @forelse ($managedUser->workspaces as $memberWorkspace)
                        <div class="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
                            <div>
                                <div class="font-medium">{{ $memberWorkspace->name }}</div>
                                <div class="mt-1 text-xs text-slate-500">Workspace ID: {{ $memberWorkspace->slug }} | {{ $memberWorkspace->pivot->role }}</div>
                            </div>
                            <form method="POST" action="{{ route('dashboard.users.workspaces.destroy', [$managedUser, $memberWorkspace]) }}" onsubmit="return confirm('Remove this workspace membership?')">
                                @csrf @method('DELETE')
                                <button class="rounded-md border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Remove</button>
                            </form>
                        </div>
                    @empty
                        <div class="px-5 py-8 text-sm text-slate-500">No workspace memberships.</div>
                    @endforelse
                </div>
            </section>
        </section>

        <aside class="space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="font-semibold">Account Actions</h2>
                <form method="POST" action="{{ route('dashboard.users.password', $managedUser) }}" class="mt-5" onsubmit="return confirm('Reset this user password and generate a new one-time password?')">
                    @csrf @method('PATCH')
                    <button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50">Reset Password</button>
                </form>
                @if ($isInitialUser && ! $managedUser->disabled_at)
                    <div class="mt-5 rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-600">This initial system user cannot be disabled or deleted.</div>
                @else
                    <div class="mt-5 flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('dashboard.users.disabled', $managedUser) }}">
                            @csrf @method('PATCH')
                            <button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50">{{ $managedUser->disabled_at ? 'Enable' : 'Disable' }}</button>
                        </form>
                        @unless ($isInitialUser)
                            <form method="POST" action="{{ route('dashboard.users.destroy', $managedUser) }}" onsubmit="return confirm('Delete this user?')">
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

@php
    $account = data_get($session->metadata, 'cloud_api.account', []);
    $nameStatus = strtoupper((string) ($account['name_status'] ?? 'UNKNOWN'));
    $newNameStatus = strtoupper((string) ($account['new_name_status'] ?? ''));
    $statusClass = fn (string $status) => match ($status) {
        'APPROVED' => 'bg-emerald-100 text-emerald-700',
        'PENDING', 'PENDING_REVIEW' => 'bg-amber-100 text-amber-800',
        'DECLINED', 'REJECTED' => 'bg-red-100 text-red-700',
        default => 'bg-slate-100 text-slate-600',
    };
@endphp

<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
    <div class="space-y-5">
    <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <header class="border-b border-slate-200 px-5 py-5 sm:px-6">
            <h3 class="font-semibold text-slate-900">Meta Cloud configuration</h3>
            <p class="mt-1 text-sm text-slate-500">Connection checks and Graph API calls run only when you submit an action.</p>
        </header>
        @if ($canManageSessions)
            <form method="POST" action="{{ route('dashboard.sessions.update', $session) }}" class="space-y-5 px-5 py-5 sm:px-6">
                @csrf @method('PATCH')
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm font-semibold text-slate-700">
                        WhatsApp Business Account ID
                        <input name="waba_id" value="{{ $session->cloudConfig?->waba_id }}" placeholder="WABA ID" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/15">
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">
                        Phone number ID
                        <input name="phone_number_id" value="{{ $session->cloudConfig?->phone_number_id }}" placeholder="From WhatsApp API Setup" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/15">
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">
                        Meta App ID <span class="font-normal text-slate-400">(media template samples)</span>
                        <input name="app_id" value="{{ $session->cloudConfig?->app_id }}" placeholder="Required only for sample media upload" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/15">
                    </label>
                    <div></div>
                    <label class="block text-sm font-semibold text-slate-700">
                        System-user access token
                        <input name="access_token" type="password" autocomplete="new-password" placeholder="Leave blank to keep current token" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/15">
                        <span class="mt-1.5 block text-xs font-normal leading-5 text-slate-500">Needs WhatsApp messaging permission; template management also needs business management permission.</span>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">
                        Meta App Secret
                        <input name="app_secret" type="password" autocomplete="new-password" placeholder="Leave blank to keep current secret" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/15">
                        <span class="mt-1.5 block text-xs font-normal leading-5 text-slate-500">Used to verify signed webhook deliveries, not to send messages.</span>
                    </label>
                </div>
                <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-5">
                    <button class="rounded-lg bg-[#128c42] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#0f7a39]">Save configuration</button>
                    <span class="text-xs text-slate-500">Saving complete credentials validates them with Meta.</span>
                </div>
            </form>
        @else
            <div class="px-6 py-12 text-sm text-slate-500">Only workspace admins can view or update Cloud credentials and webhook verification details.</div>
        @endif
    </section>

    @if ($canManageSessions)
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-5 sm:px-6">
                <div>
                    <h3 class="font-semibold text-slate-900">WhatsApp account</h3>
                    <p class="mt-1 text-sm text-slate-500">Manage security and the customer-facing name for this business phone number.</p>
                </div>
                <form method="POST" action="{{ route('dashboard.sessions.cloud-account.refresh', $session) }}">
                    @csrf
                    <button class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Refresh account status</button>
                </form>
            </header>

            <div class="grid gap-4 border-b border-slate-100 bg-slate-50/60 px-5 py-4 text-sm sm:grid-cols-3 sm:px-6">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Current display name</div>
                    <div class="mt-1 font-semibold text-slate-900">{{ $account['verified_name'] ?? data_get($session->metadata, 'cloud_api.verified_name', 'Not refreshed') }}</div>
                    <span class="mt-2 inline-flex rounded-full px-2 py-1 text-[10px] font-bold {{ $statusClass($nameStatus) }}">{{ str_replace('_', ' ', $nameStatus) }}</span>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Requested display name</div>
                    <div class="mt-1 font-semibold text-slate-900">{{ $account['new_display_name'] ?? 'None' }}</div>
                    @if ($newNameStatus !== '')
                        <span class="mt-2 inline-flex rounded-full px-2 py-1 text-[10px] font-bold {{ $statusClass($newNameStatus) }}">{{ str_replace('_', ' ', $newNameStatus) }}</span>
                    @endif
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Two-step verification</div>
                    <div class="mt-1 font-semibold text-slate-900">
                        {{ array_key_exists('is_pin_enabled', $account) ? ($account['is_pin_enabled'] ? 'Enabled' : 'Not enabled') : 'Not refreshed' }}
                    </div>
                    @if (filled($account['refreshed_at'] ?? null))
                        <div class="mt-2 text-xs text-slate-500">Checked {{ \Illuminate\Support\Carbon::parse($account['refreshed_at'])->diffForHumans() }}</div>
                    @endif
                </div>
            </div>

            <div class="grid gap-6 px-5 py-5 sm:px-6 lg:grid-cols-2">
                <div>
                    <h4 class="text-sm font-semibold text-slate-900">Set two-step verification PIN</h4>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Choose a six-digit PIN. LaraWA sends it directly to Meta and does not store it.</p>
                    <form method="POST" action="{{ route('dashboard.sessions.cloud-account.two-factor', $session) }}" class="mt-4 space-y-3">
                        @csrf
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block text-xs font-semibold text-slate-600">
                                New PIN
                                <input name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="new-password" required class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal tracking-[0.35em] outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/15">
                            </label>
                            <label class="block text-xs font-semibold text-slate-600">
                                Confirm PIN
                                <input name="pin_confirmation" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="new-password" required class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal tracking-[0.35em] outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/15">
                            </label>
                        </div>
                        <button class="rounded-lg bg-[#128c42] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#0f7a39]">Update PIN</button>
                    </form>
                </div>

                <div class="border-t border-slate-100 pt-6 lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0">
                    <h4 class="text-sm font-semibold text-slate-900">Request a new display name</h4>
                    <p class="mt-1 text-xs leading-5 text-slate-500">The name is reviewed by Meta before it can be applied to this phone number.</p>
                    <form method="POST" action="{{ route('dashboard.sessions.cloud-account.display-name.request', $session) }}" class="mt-4 space-y-3">
                        @csrf
                        <label class="block text-xs font-semibold text-slate-600">
                            New display name
                            <input name="new_display_name" value="{{ old('new_display_name') }}" minlength="3" maxlength="512" required placeholder="Your business name" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/15">
                        </label>
                        <button class="rounded-lg border border-[#128c42] px-4 py-2.5 text-sm font-semibold text-[#128c42] hover:bg-emerald-50">Request Meta approval</button>
                    </form>

                    <form method="POST" action="{{ route('dashboard.sessions.cloud-account.display-name.apply', $session) }}" class="mt-5 border-t border-slate-100 pt-5">
                        @csrf
                        <label class="block text-xs font-semibold text-slate-600">
                            Two-step verification PIN
                            <input name="display_name_pin" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="current-password" required class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal tracking-[0.35em] outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/15">
                        </label>
                        <button @disabled($newNameStatus !== 'APPROVED') class="mt-3 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:bg-slate-300">Apply approved name</button>
                        @if ($newNameStatus !== 'APPROVED')
                            <p class="mt-2 text-xs text-slate-500">Refresh the account status after Meta approves the request.</p>
                        @endif
                    </form>
                </div>
            </div>
        </section>
    @endif
    </div>

    <aside class="space-y-5">
        @if ($canManageSessions)
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="font-semibold text-slate-900">Webhook setup</h3>
                    <span class="rounded-full bg-emerald-100 px-2 py-1 text-[10px] font-bold text-emerald-700">PER SESSION</span>
                </div>
                <p class="mt-2 text-xs leading-5 text-slate-500">Copy these values into Meta App Dashboard → WhatsApp → Configuration.</p>
                <label class="mt-4 block text-xs font-semibold text-slate-600" for="meta-callback-url">Callback URL</label>
                <input id="meta-callback-url" type="text" readonly onclick="this.select()" value="{{ url('/api/meta/whatsapp/webhook/'.$session->uuid) }}" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 p-2.5 font-mono text-xs text-slate-900">
                <label class="mt-4 block text-xs font-semibold text-slate-600" for="meta-verify-token">Verify token</label>
                <input id="meta-verify-token" type="text" readonly onclick="this.select()" value="{{ $session->cloudConfig?->verify_token }}" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 p-2.5 font-mono text-xs text-slate-900">
                <p class="mt-3 text-xs leading-5 text-slate-500">The verify token was generated by LaraWA. Subscribe only to the <code class="rounded bg-slate-100 px-1 py-0.5">messages</code> webhook field.</p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-semibold text-slate-900">Connection controls</h3>
                <p class="mt-2 text-xs leading-5 text-slate-500">There is no background connection check on this page.</p>
                <form method="POST" action="{{ route('dashboard.sessions.refresh', $session) }}" class="mt-4">
                    @csrf
                    <button class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Test connection now</button>
                </form>
            </section>

            <section class="rounded-xl border border-red-200 bg-white p-5 shadow-sm">
                <h3 class="font-semibold text-red-800">Delete session</h3>
                <p class="mt-2 text-xs leading-5 text-slate-500">Deletes this LaraWA session and its locally stored configuration. It does not remove your Meta business number.</p>
                <form method="POST" action="{{ route('dashboard.sessions.destroy', $session) }}" class="mt-4" onsubmit="return confirm('Delete this Official Cloud API session and its local data?')">
                    @csrf @method('DELETE')
                    <button class="w-full rounded-lg border border-red-300 px-3 py-2.5 text-sm font-semibold text-red-700 hover:bg-red-50">Delete session</button>
                </form>
            </section>
        @endif
    </aside>
</div>

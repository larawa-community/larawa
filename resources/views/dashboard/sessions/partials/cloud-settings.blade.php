<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
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

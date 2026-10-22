<x-layouts.app :workspace="$workspace" title="Phone registration test">
    <div class="mx-auto max-w-3xl space-y-5">
        <a href="{{ route('dashboard.settings.index') }}" class="text-sm font-semibold text-[#128c42]">← System Settings</a>
        <section class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-lg font-semibold">Temporary Cloud API registration test</h2>
            <p class="mt-2 text-sm text-slate-600">Register a verified phone number using its saved session access token. The shared Meta App ID and App Secret are not the authorization credentials for this request.</p>
            <p class="mt-2 text-sm text-slate-600">Use this for standard Cloud API signup after SMS/voice verification. Existing WhatsApp Business app numbers using coexistence need their dedicated onboarding flow.</p>
            <form method="POST" action="{{ route('dashboard.settings.meta-registration.store') }}" class="mt-6 space-y-5" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                @csrf
                <label class="block text-sm font-semibold">Cloud API session
                    <select name="session_uuid" required class="mt-2 block w-full rounded-md border border-slate-300 px-3 py-2">
                        <option value="">Choose a session</option>
                        @foreach ($sessions as $cloudSession)
                            <option value="{{ $cloudSession->uuid }}" @selected(old('session_uuid') === $cloudSession->uuid)>
                                {{ $cloudSession->workspace->name }} / {{ $cloudSession->name }} — Phone ID: {{ $cloudSession->cloudConfig?->phone_number_id ?: 'missing' }} — WABA: {{ $cloudSession->cloudConfig?->waba_id ?: 'missing' }}{{ $cloudSession->cloudConfig?->isConfigured() ? '' : ' (configuration incomplete)' }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm font-semibold">Six-digit two-step verification PIN
                    <input name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" required class="mt-2 block w-full rounded-md border border-slate-300 px-3 py-2">
                    <span class="mt-2 block text-xs font-normal text-slate-500">Use the existing PIN if already set; otherwise choose one and keep it safe. This is not the SMS code. LaraWA does not save this PIN.</span>
                </label>
                <label class="flex gap-3 text-sm"><input type="checkbox" name="confirmed" value="1" required class="mt-1 rounded border-slate-300">I have verified this number and want to send a live registration request to Meta.</label>
                <button type="submit" class="rounded-md bg-[#128c42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Register phone number</button>
            </form>
            @if ($sessions->isEmpty())
                <p class="mt-4 text-sm text-amber-700">Create an Official Cloud API session and save its credentials first.</p>
            @endif
        </section>
        @if ($result = session('registration_result'))
            <section class="rounded-lg border border-slate-200 bg-white p-6" role="status">
                <h2 class="font-semibold">{{ $result['success'] ? 'Meta accepted registration' : 'Registration was not confirmed' }}</h2>
                <pre class="mt-3 overflow-auto whitespace-pre-wrap break-all rounded-md bg-slate-50 p-4 text-xs">{{ json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                <p class="mt-3 text-sm text-slate-600">{{ $result['success'] ? 'Refresh WhatsApp Manager to check the phone status. Webhook subscription and message delivery still need separate checks.' : 'Review the Meta error before retrying. An unconfirmed response does not prove the phone is unregistered.' }}</p>
            </section>
        @endif
    </div>
</x-layouts.app>

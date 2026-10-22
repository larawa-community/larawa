<section id="phone-registration" class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
    <h3 class="font-semibold text-slate-900">Cloud API phone registration</h3>
    <p class="mt-2 text-sm text-slate-600">Register this session’s verified phone number using the configured provider token. This sends a live request to Meta.</p>
    <p class="mt-2 text-sm text-slate-600">Phone number ID: <span class="font-mono">{{ $session->cloudConfig?->phone_number_id ?: 'Not configured' }}</span></p>
    <p class="mt-2 text-xs text-slate-500">Complete SMS/voice verification first. WhatsApp Business app coexistence requires its dedicated onboarding flow. Registration does not change the saved WABA or phone number ID.</p>
    @if ($registrationErrors = session('registration_errors_'.$session->uuid))
        <div role="alert" class="mt-4 rounded-lg bg-red-50 p-4 text-sm text-red-800">
            @foreach ($registrationErrors as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif
    <form method="POST" action="{{ route('dashboard.sessions.cloud-account.register', $session) }}" class="mt-4 space-y-4" data-meta-action-form data-meta-action-label="Registering the phone with Meta…" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
        @csrf
        <label class="block text-sm font-semibold">Six-digit two-step verification PIN
            <input name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" required class="mt-2 block w-full rounded-md border border-slate-300 px-3 py-2">
            <span class="mt-1 block text-xs font-normal text-slate-500">Use the existing PIN if set; otherwise choose one and keep it safe. This is not the SMS code. The PIN is not saved.</span>
        </label>
        <label class="flex gap-2 text-sm"><input name="confirmed" type="checkbox" value="1" required class="mt-1 rounded border-slate-300">I have verified this number and want to register it with Meta.</label>
        <button type="submit" @disabled(! filled($session->cloudConfig?->phone_number_id)) class="rounded-lg bg-[#128c42] px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Register phone number</button>
    </form>
    @if ($registration = session('registration_result_'.$session->uuid))
        <div class="mt-5 space-y-4 border-t border-slate-200 pt-5" role="status">
            <h4 class="font-semibold">{{ $registration['success'] ? 'Meta accepted registration' : 'Registration was not confirmed' }}</h4>
            @if ($registration['error'])
                <p role="alert" class="whitespace-pre-wrap break-words rounded-lg bg-red-50 p-4 text-sm text-red-800">{{ $registration['error'] }}</p>
            @endif
            <p class="text-xs text-slate-500">Access tokens, PINs, and cookies are redacted. Webhook subscription and message delivery require separate checks.</p>
            <div><h4 class="text-sm font-semibold">Request</h4><pre class="mt-2 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-50 p-3 text-xs">{{ $registration['request']['method'] }} {{ $registration['request']['url'] }}</pre></div>
            @foreach (['Request headers' => $registration['request']['headers'], 'Request body' => $registration['request']['body'], 'Response headers' => $registration['response']['headers']] as $label => $payload)
                <div><h4 class="text-sm font-semibold">{{ $label }}</h4><pre class="mt-2 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-50 p-3 text-xs">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre></div>
            @endforeach
            <p class="text-sm font-semibold">Response status: {{ $registration['response']['status'] ?? 'No HTTP response received' }}</p>
            <div><h4 class="text-sm font-semibold">Response body</h4><pre class="mt-2 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-50 p-3 text-xs">{{ $registration['response']['body'] ?? 'No response body received.' }}</pre></div>
        </div>
    @endif
</section>

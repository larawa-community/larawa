@php
    $profile = data_get($session->metadata, 'cloud_api.business_profile', []);
    if (($profile['phone_number_id'] ?? null) !== $session->cloudConfig?->phone_number_id) {
        $profile = [];
    }
    $profileConfigured = $session->cloudConfig?->isConfigured();
    $profileInputClass = 'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/15';
    $pictureUrl = $profile['profile_picture_url'] ?? '';
@endphp

<section class="rounded-xl border border-slate-200 bg-white shadow-sm">
    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-5 sm:px-6">
        <div>
            <h3 class="font-semibold text-slate-900">Business profile</h3>
            <p class="mt-1 text-sm text-slate-500">The photo and business information customers see on WhatsApp.</p>
            <p class="mt-2 text-xs text-slate-500">Phone number ID: {{ $session->cloudConfig?->phone_number_id ?: 'Not configured' }}</p>
        </div>
        <form method="POST" action="{{ route('dashboard.sessions.cloud-account.business-profile.refresh', $session) }}" data-meta-action-form data-meta-action-label="Refreshing business profile…">
            @csrf
            <button @disabled(! $profileConfigured) class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50">Refresh profile</button>
        </form>
    </header>

    @if (filled($profileRefreshError ?? null))
        <div role="status" class="border-b border-amber-200 bg-amber-50 px-5 py-3 text-xs leading-5 text-amber-800 sm:px-6">
            Live business profile could not be refreshed: {{ $profileRefreshError }} Saved values are shown where available.
        </div>
    @endif
    @if (! $profileConfigured)
        <p class="px-5 pt-5 text-sm text-amber-800 sm:px-6">Complete the Meta Cloud configuration to load and save your business profile.</p>
    @endif

    <div class="px-5 py-5 sm:px-6">
        <form method="POST" enctype="multipart/form-data" action="{{ route('dashboard.sessions.cloud-account.business-profile.update', $session) }}" data-meta-action-form data-meta-action-label="Uploading profile picture to Meta…">
            @csrf
            <div class="flex flex-wrap items-start gap-4">
                @if (filter_var($pictureUrl, FILTER_VALIDATE_URL) && parse_url($pictureUrl, PHP_URL_SCHEME) === 'https')
                    <img src="{{ $pictureUrl }}" alt="Current WhatsApp business profile picture" referrerpolicy="no-referrer" class="h-20 w-20 rounded-xl border border-slate-200 object-cover">
                @else
                    <div class="flex h-20 w-20 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-xs text-slate-500">No photo</div>
                @endif
                <div class="min-w-0 flex-1">
                    <label class="block text-sm font-semibold text-slate-700">
                        Profile picture
                        <input type="file" name="profile_picture" accept="image/jpeg,image/png" required @disabled(! $profileConfigured) class="mt-2 block w-full text-sm font-normal text-slate-600 file:mr-3 file:rounded-lg file:border file:border-slate-300 file:bg-white file:px-3 file:py-2 file:font-semibold file:text-slate-700">
                    </label>
                    <p class="mt-2 text-xs leading-5 text-slate-500">Square JPG or PNG, at least 640 × 640 pixels, up to 5 MB. After an upload error, select your file again.</p>
                    @error('profile_picture') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    <button @disabled(! $profileConfigured) class="mt-3 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50">Update picture</button>
                </div>
            </div>
        </form>
    </div>

    <form method="POST" action="{{ route('dashboard.sessions.cloud-account.business-profile.update', $session) }}" class="border-t border-slate-100 px-5 py-5 sm:px-6" data-meta-action-form data-meta-action-label="Saving business profile to Meta…">
        @csrf
        <div class="space-y-4">
            <label class="block text-sm font-semibold text-slate-700">
                Category
                <select name="vertical" class="{{ $profileInputClass }}">
                    <option value="">Keep current category{{ filled($profile['vertical'] ?? null) ? ' ('.($profile['vertical']).')' : '' }}</option>
                    @foreach (\App\Services\MetaWhatsappBusinessProfileService::CATEGORIES as $value => $label)
                        <option value="{{ $value }}" @selected(old('vertical', $profile['vertical'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('vertical') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            @foreach (['about' => ['About', 139, 'text'], 'description' => ['Description', 512, 'textarea'], 'address' => ['Address', 256, 'text'], 'email' => ['Email', 128, 'email']] as $field => [$label, $limit, $type])
                <label class="block text-sm font-semibold text-slate-700" data-profile-field>
                    {{ $label }} <span class="font-normal text-slate-400">{{ $field === 'about' ? '' : '· Optional' }}</span>
                    @if ($type === 'textarea')
                        <textarea name="{{ $field }}" rows="3" maxlength="{{ $limit }}" class="{{ $profileInputClass }}" data-profile-count-input>{{ old($field, $profile[$field] ?? '') }}</textarea>
                    @else
                        <input type="{{ $type }}" name="{{ $field }}" value="{{ old($field, $profile[$field] ?? '') }}" maxlength="{{ $limit }}" @required($field === 'about') class="{{ $profileInputClass }}" data-profile-count-input>
                    @endif
                    <span class="mt-1 block text-right text-xs font-normal text-slate-400" data-profile-count>{{ mb_strlen(old($field, $profile[$field] ?? '') ?? '') }}/{{ $limit }}</span>
                    @error($field) <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            @endforeach
            <div class="grid gap-4 sm:grid-cols-2">
                @for ($i = 0; $i < 2; $i++)
                    <label class="block text-sm font-semibold text-slate-700" data-profile-field>
                        Website {{ $i + 1 }} <span class="font-normal text-slate-400">· Optional</span>
                        <input type="url" name="websites[{{ $i }}]" value="{{ old('websites.'.$i, $profile['websites'][$i] ?? '') }}" maxlength="256" placeholder="https://www.example.com" class="{{ $profileInputClass }}" data-profile-count-input>
                        <span class="mt-1 block text-right text-xs font-normal text-slate-400" data-profile-count>{{ mb_strlen(old('websites.'.$i, $profile['websites'][$i] ?? '') ?? '') }}/256</span>
                        @error('websites.'.$i) <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                @endfor
            </div>
        </div>
        <div class="mt-5 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-5">
            <button @disabled(! $profileConfigured) class="rounded-lg bg-[#128c42] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#0f7a39] disabled:cursor-not-allowed disabled:opacity-50">Save business profile</button>
            <span class="text-xs text-slate-500">Changes are saved directly to Meta.</span>
        </div>
    </form>
</section>

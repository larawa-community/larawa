<div>
    <label class="text-sm font-medium text-slate-700" for="env_{{ $field['key'] }}">{{ $field['key'] }}</label>

    @if ($field['key'] === 'META_WEBHOOK_VERIFY_TOKEN')
        @if ($metaTokenError)
            <p role="alert" class="mt-2 text-sm text-red-700">{{ $metaTokenError }}</p>
        @endif
        <div class="mt-2 flex gap-2">
            <input id="env_META_WEBHOOK_VERIFY_TOKEN" type="text" readonly value="{{ $field['value'] }}" onclick="this.select()" class="min-w-0 flex-1 rounded-md border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-sm">
            <button type="button" @disabled($metaTokenError) data-copy-meta-token class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Copy token</button>
        </div>
        <p data-meta-token-copy-status role="status" class="mt-1 text-xs text-slate-500">Stored encrypted in the database. Copy this token into your Meta app.</p>
        <button type="submit" form="regenerate-meta-token" @disabled($metaTokenError) class="mt-2 rounded-md border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Revoke and regenerate</button>
    @elseif ($field['type'] === 'boolean')
        <input type="hidden" name="env[{{ $field['key'] }}]" value="false">
        <label class="mt-2 flex items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm">
            <input
                id="env_{{ $field['key'] }}"
                type="checkbox"
                name="env[{{ $field['key'] }}]"
                value="true"
                @checked(filter_var(old("env.{$field['key']}", $field['value']), FILTER_VALIDATE_BOOL))
                class="h-4 w-4 rounded border-slate-300 text-[#128c42] focus:ring-[#25d366]"
            >
            <span>Enabled</span>
        </label>
    @elseif ($field['type'] === 'select')
        <select
            id="env_{{ $field['key'] }}"
            name="env[{{ $field['key'] }}]"
            autocomplete="off"
            class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 font-mono text-sm text-slate-900 shadow-sm focus:border-[#25d366] focus:outline-none focus:ring-2 focus:ring-[#25d366]/20"
        >
            @foreach ($field['options'] as $option)
                <option value="{{ $option }}" @selected(old("env.{$field['key']}", $field['value']) === $option)>{{ $option }}</option>
            @endforeach
        </select>
    @else
        <input
            id="env_{{ $field['key'] }}"
            type="{{ $field['type'] }}"
            name="env[{{ $field['key'] }}]"
            value="{{ old("env.{$field['key']}", $field['value']) }}"
            autocomplete="off"
            class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm text-slate-900 shadow-sm focus:border-[#25d366] focus:outline-none focus:ring-2 focus:ring-[#25d366]/20"
        >
    @endif

    @if ($field['comment'] !== '')
        <p class="mt-1 text-xs text-slate-500">{{ $field['comment'] }}</p>
    @elseif ($field['example'] !== '')
        <p class="mt-1 break-all text-xs text-slate-500">Example: <span class="font-mono">{{ $field['example'] }}</span></p>
    @endif
</div>

<div>
    <label class="text-sm font-medium text-slate-700" for="env_{{ $field['key'] }}">{{ $field['key'] }}</label>

    @if ($field['type'] === 'boolean')
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

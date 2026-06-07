@php
    $locales = $locales ?? app(\App\Services\LocaleResolver::class)->availableLocales();
    $selectorId = $selectorId ?? 'locale';
@endphp

@if (count($locales) > 1)
    <form method="POST" action="{{ route('locale.update') }}" class="flex shrink-0 items-center justify-end gap-2" data-auto-submit>
        @csrf
        <label class="sr-only" for="{{ $selectorId }}">{{ __('dashboard.language.label') }}</label>
        <select id="{{ $selectorId }}" name="locale" class="max-w-36 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700">
            @foreach ($locales as $locale => $definition)
                <option value="{{ $locale }}" @selected(app()->getLocale() === $locale)>{{ $definition['native'] }}</option>
            @endforeach
        </select>
        <button class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50" data-auto-submit-fallback>{{ __('dashboard.language.apply') }}</button>
    </form>
@endif

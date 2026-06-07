@php
    app(\App\Services\LocaleResolver::class)->apply(request());
    $title = __('errors.404.title');
    $detail = trim($exception->getMessage());
    $isRouteMessage = str_starts_with($detail, 'The route ') && str_ends_with($detail, ' could not be found.');
    $detail = $detail && $detail !== 'Not Found' && ! $isRouteMessage
        ? $detail
        : __('errors.404.default');
    $primaryLabel = auth()->check() ? __('errors.back_dashboard') : __('errors.sign_in');
    $primaryHref = auth()->check() ? route('dashboard') : route('login');
@endphp

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} - LaraWA</title>
    @include('partials.favicons')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">
    <main class="flex min-h-screen items-center justify-center bg-slate-950 px-4 py-10">
        <section class="w-full max-w-xl rounded-lg border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <div class="mb-6">
                @include('partials.brand-lockup')
                <div>
                    <h1 class="mt-3 text-2xl font-semibold text-slate-950">{{ $title }}</h1>
                </div>
            </div>

            <div class="mb-5 inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700">
                <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true"><path d="M11 18h2v-2h-2v2Zm1-16a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16Zm0-14a4 4 0 0 0-4 4h2a2 2 0 1 1 2 2 1 1 0 0 0-1 1v2h2v-1.2A4 4 0 0 0 12 6Z"/></svg>
                {{ __('errors.404.badge') }}
            </div>

            <p class="text-base leading-7 text-slate-600">{{ $detail }}</p>

            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ $primaryHref }}" class="inline-flex items-center gap-2 rounded-md bg-[#25d366] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#1eb858]">
                    <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8h5Z"/></svg>
                    {{ $primaryLabel }}
                </a>
                <button type="button" onclick="history.back()" class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true"><path d="M20 11H7.8l5.6-5.6L12 4 4 12l8 8 1.4-1.4L7.8 13H20v-2Z"/></svg>
                    {{ __('errors.go_back') }}
                </button>
            </div>
        </section>
    </main>
</body>
</html>

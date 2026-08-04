@php
    $statusTone = match ($session->status) {
        'ready' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'failed' => 'border-red-200 bg-red-50 text-red-700',
        default => 'border-amber-200 bg-amber-50 text-amber-800',
    };
@endphp

<x-layouts.app :workspace="$workspace" :title="$session->name" :compact-chrome="true">
    <div class="space-y-3 sm:space-y-5" data-cloud-session-workspace>
        <header class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="bg-[linear-gradient(125deg,#062d22_0%,#0d6848_68%,#18a56c_100%)] px-4 py-4 text-white sm:px-7 sm:py-6">
                <div class="flex flex-wrap items-start justify-between gap-3 sm:gap-5">
                    <div>
                        <a href="{{ route('dashboard.sessions.index') }}" class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-100 hover:text-white">← All sessions</a>
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <h2 class="text-xl font-semibold tracking-tight sm:text-2xl">{{ $session->name }}</h2>
                            <span class="rounded-full border border-white/20 bg-white/10 px-2.5 py-1 text-xs font-semibold">Official Cloud API</span>
                        </div>
                        <p class="mt-2 font-mono text-xs text-emerald-100 {{ $activeSection === 'conversations' ? 'hidden sm:block' : '' }}">{{ $session->uuid }}</p>
                    </div>
                    <div class="w-full grid-cols-2 gap-2 text-sm sm:w-auto sm:flex {{ $activeSection === 'conversations' ? 'hidden' : 'grid' }}">
                        <div class="min-w-0 rounded-lg border border-white/15 bg-white/10 px-3 py-2.5 sm:px-4">
                            <div class="text-xs text-emerald-100">Business number</div>
                            <div class="mt-0.5 truncate font-semibold">{{ $session->phone_number ?: 'Not connected' }}</div>
                        </div>
                        <div class="min-w-0 rounded-lg border border-white/15 bg-white/10 px-3 py-2.5 sm:px-4">
                            <div class="text-xs text-emerald-100">Connection</div>
                            <div class="mt-0.5 font-semibold capitalize">{{ $session->status }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <nav class="flex overflow-x-auto border-t border-slate-100 px-3 sm:px-5" aria-label="Cloud session sections">
                @foreach ([
                    'conversations' => ['label' => 'Conversations', 'route' => route('dashboard.sessions.conversations.index', $session)],
                    'templates' => ['label' => 'Templates', 'route' => route('dashboard.sessions.templates.index', $session)],
                    'settings' => ['label' => 'Settings', 'route' => route('dashboard.sessions.cloud-settings', $session)],
                ] as $section => $item)
                    <a href="{{ $item['route'] }}" class="whitespace-nowrap border-b-2 px-4 py-3.5 text-sm font-semibold {{ $activeSection === $section ? 'border-[#128c42] text-[#128c42]' : 'border-transparent text-slate-500 hover:text-slate-900' }}">
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
        </header>

        @if ($activeSection === 'conversations')
            @include('dashboard.sessions.partials.cloud-conversations')
        @elseif ($activeSection === 'templates')
            @include('dashboard.sessions.partials.cloud-templates')
        @else
            @include('dashboard.sessions.partials.cloud-settings')
        @endif

        <div class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/40 px-4 backdrop-blur-sm" data-meta-action-loading role="status" aria-live="polite" aria-hidden="true">
            <div class="w-full max-w-sm rounded-2xl border border-white/70 bg-white p-6 text-center shadow-2xl">
                <svg class="mx-auto h-10 w-10 animate-spin text-[#128c42]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-20" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
                    <path class="opacity-90" d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                </svg>
                <h2 class="mt-4 text-base font-semibold text-slate-900" data-meta-action-loading-label>Contacting Meta…</h2>
                <p class="mt-1 text-sm leading-5 text-slate-500">Please keep this page open. This request can take a moment.</p>
            </div>
        </div>
    </div>
</x-layouts.app>

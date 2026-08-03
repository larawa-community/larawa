@php
    $statusTone = match ($session->status) {
        'ready' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'failed' => 'border-red-200 bg-red-50 text-red-700',
        default => 'border-amber-200 bg-amber-50 text-amber-800',
    };
@endphp

<x-layouts.app :workspace="$workspace" :title="$session->name">
    <div class="space-y-5" data-cloud-session-workspace>
        <header class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="bg-[linear-gradient(125deg,#062d22_0%,#0d6848_68%,#18a56c_100%)] px-5 py-6 text-white sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-5">
                    <div>
                        <a href="{{ route('dashboard.sessions.index') }}" class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-100 hover:text-white">← All sessions</a>
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <h2 class="text-2xl font-semibold tracking-tight">{{ $session->name }}</h2>
                            <span class="rounded-full border border-white/20 bg-white/10 px-2.5 py-1 text-xs font-semibold">Official Cloud API</span>
                        </div>
                        <p class="mt-2 font-mono text-xs text-emerald-100">{{ $session->uuid }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-sm sm:flex">
                        <div class="rounded-lg border border-white/15 bg-white/10 px-4 py-2.5">
                            <div class="text-xs text-emerald-100">Business number</div>
                            <div class="mt-0.5 font-semibold">{{ $session->phone_number ?: 'Not connected' }}</div>
                        </div>
                        <div class="rounded-lg border border-white/15 bg-white/10 px-4 py-2.5">
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
    </div>
</x-layouts.app>

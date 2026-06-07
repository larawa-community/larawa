<x-layouts.app :workspace="$workspace" :title="__('dashboard.dashboard.title')">
    <div class="grid gap-4 md:grid-cols-4">
        @foreach ($stats as $label => $value)
            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <div class="text-sm capitalize text-slate-500">{{ __("dashboard.stats.{$label}") }}</div>
                <div class="mt-2 text-3xl font-semibold">{{ $value }}</div>
            </div>
        @endforeach
    </div>
    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-5 py-4 font-semibold">{{ __('dashboard.dashboard.recent_sessions') }}</div>
            <div class="divide-y divide-slate-100">
                @forelse ($sessions as $session)
                    <a href="{{ route('dashboard.sessions.show', $session) }}" class="flex items-center justify-between px-5 py-4 hover:bg-slate-50">
                        <div>
                            <div class="font-medium">{{ $session->name }}</div>
                            <div class="text-sm text-slate-500">{{ $session->phone_number ?: $session->uuid }}</div>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ $session->status }}</span>
                    </a>
                @empty
                    <div class="px-5 py-8 text-sm text-slate-500">{{ __('dashboard.dashboard.no_sessions') }}</div>
                @endforelse
            </div>
        </section>
        <section class="rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-5 py-4 font-semibold">{{ __('dashboard.dashboard.recent_messages') }}</div>
            <div class="divide-y divide-slate-100">
                @forelse ($messages as $message)
                    <div class="px-5 py-4">
                        <div class="flex items-center justify-between gap-3">
                            <div class="truncate font-medium">{{ $message->body ?: $message->type }}</div>
                            <span class="text-xs text-slate-500">{{ $message->direction }}</span>
                        </div>
                        <div class="mt-1 text-sm text-slate-500">{{ $message->from }} -> {{ $message->to }}</div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-sm text-slate-500">{{ __('dashboard.dashboard.no_messages') }}</div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.app>

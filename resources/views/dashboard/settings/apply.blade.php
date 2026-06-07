<x-layouts.app :workspace="$workspace" title="Apply Runtime Settings">
    <div class="max-w-3xl space-y-6">
        <a href="{{ route('dashboard.settings.index') }}" class="inline-flex text-sm font-semibold text-[#128c42]">Back to System Settings</a>

        <section class="rounded-lg border border-amber-200 bg-amber-50">
            <div class="border-b border-amber-200 px-5 py-4">
                <h2 class="font-semibold text-amber-950">Apply Runtime Settings</h2>
                <p class="mt-1 text-sm text-amber-800">Saved environment changes are pending until Laravel runtime caches are rebuilt. Applying may briefly interrupt requests while cached configuration, routes, and views are refreshed.</p>
            </div>
            <div class="space-y-5 p-5">
                <div>
                    <h3 class="text-sm font-semibold text-amber-950">Commands</h3>
                    <div class="mt-3 rounded-md bg-white/70 p-4 font-mono text-sm text-amber-950">
                        @foreach ($commands as $command)
                            <div>{{ $command }}</div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-md border border-amber-200 bg-white/70 px-4 py-3 text-sm text-amber-900">
                    Restart queue workers and other long-running PHP processes after applying settings so they read the new environment.
                </div>

                <form method="POST" action="{{ route('dashboard.settings.apply') }}">
                    @csrf
                    <button class="rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Apply Settings</button>
                </form>
            </div>
        </section>
    </div>
</x-layouts.app>

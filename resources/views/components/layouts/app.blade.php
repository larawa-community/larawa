@props(['title' => 'LaraWA', 'workspace' => null, 'chrome' => true, 'compactChrome' => false])
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'LaraWA' }}</title>
    @include('partials.favicons')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @foreach (app(\App\Services\Plugins\PluginManager::class)->enabled() as $assetPlugin)
        @foreach (($assetPlugin->manifest['assets'] ?? []) as $assetPath)
            @php
                $assetUrl = is_string($assetPath) && preg_match('/^(https?:\/\/|\/)/', $assetPath)
                    ? $assetPath
                    : asset($assetPath);
            @endphp
            @if (is_string($assetPath) && str_ends_with($assetPath, '.css'))
                <link rel="stylesheet" href="{{ $assetUrl }}">
            @elseif (is_string($assetPath) && str_ends_with($assetPath, '.js'))
                <script src="{{ $assetUrl }}" defer></script>
            @endif
        @endforeach
    @endforeach
</head>
<body class="min-h-screen">
    @if (auth()->check() && $chrome)
        <div class="flex h-screen overflow-hidden">
            <aside class="hidden h-screen shrink-0 lg:block {{ $compactChrome ? 'relative w-20' : 'w-64' }}" @if($compactChrome) data-collapsible-sidebar @endif>
                <div class="flex h-screen flex-col border-r border-slate-200 bg-white {{ $compactChrome ? 'group/sidebar absolute inset-y-0 left-0 z-30 w-20 hover:w-64 hover:shadow-xl' : 'w-64' }}" @if($compactChrome) data-sidebar-panel @endif>
                <div class="flex h-16 shrink-0 items-center gap-3 border-b border-slate-200 {{ $compactChrome ? 'px-5 group-hover/sidebar:px-4' : 'px-6' }}">
                    <img src="{{ asset('images/laraWA-icon.png') }}" alt="LaraWA" class="h-9 w-9 shrink-0">
                    <div class="min-w-0 {{ $compactChrome ? 'hidden group-hover/sidebar:block' : '' }}" @if($compactChrome) data-sidebar-label @endif>
                        <div class="truncate text-sm font-semibold text-slate-900">{{ $workspace->name ?? 'Workspace' }}</div>
                        <div class="mt-0.5 truncate text-xs text-slate-500">Powered by LaraWA</div>
                    </div>
                </div>
                <nav class="min-h-0 flex-1 space-y-1 overflow-y-auto px-3 py-4 text-sm">
                    @php
                        $user = auth()->user();
                        $isSiteAdmin = $user?->isSiteAdmin();
                        $canManageWorkspace = $workspace && $user?->can('workspace.manage', $workspace);
                        $role = $workspace && $user ? $user->roleForWorkspace($workspace) : null;
                        $roleLabel = match ($role) {
                            'site_admin' => __('dashboard.roles.site_admin'),
                            'workspace_admin' => __('dashboard.roles.workspace_admin'),
                            'workspace_user' => __('dashboard.roles.workspace_user'),
                            default => $isSiteAdmin ? __('dashboard.roles.site_admin') : __('dashboard.roles.user'),
                        };
                        $items = [
                            [__('dashboard.nav.dashboard'), route('dashboard'), 'dashboard', 'M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z'],
                        ];

                        if ($isSiteAdmin) {
                            $items = array_merge($items, [
                                [__('dashboard.nav.workspaces'), route('dashboard.workspaces.index'), 'dashboard.workspaces.*', 'M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm9 0h7v7h-7v-7Z'],
                                [__('dashboard.nav.users'), route('dashboard.users.index'), 'dashboard.users.*', 'M16 11a4 4 0 1 0-8 0 4 4 0 0 0 8 0Zm-12 9a8 8 0 0 1 16 0H4Zm14-12a3 3 0 0 1 3 3v1h-2v-1a1 1 0 0 0-1-1V8Z'],
                                [__('dashboard.nav.sessions'), route('dashboard.sessions.index'), 'dashboard.sessions.*', 'M7 7h10v10H7V7Zm-4 3h2v4H3v-4Zm16 0h2v4h-2v-4ZM10 3h4v2h-4V3Zm0 16h4v2h-4v-2Z'],
                                [__('dashboard.nav.api_keys'), route('dashboard.api-keys.index'), 'dashboard.api-keys.*', 'M7 14a5 5 0 1 1 4.9-6H21v4h-3v3h-4v-3h-2.1A5 5 0 0 1 7 14Zm0-3a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z'],
                                [__('dashboard.nav.marketplace'), route('dashboard.marketplace.index'), 'dashboard.marketplace.*', 'M4 4h16v4H4V4Zm1 6h14l-1 10H6L5 10Zm4 2v6h2v-6H9Zm4 0v6h2v-6h-2Z'],
                                [__('dashboard.nav.messages'), route('dashboard.messages.index'), 'dashboard.messages.*', 'M4 5h16v10H7l-3 3V5Z'],
                                [__('dashboard.nav.audit_logs'), route('dashboard.audit.index'), 'dashboard.audit.*', 'M5 3h14v18H5V3Zm3 4h8v2H8V7Zm0 4h8v2H8v-2Zm0 4h5v2H8v-2Z'],
                                [__('dashboard.nav.settings'), route('dashboard.settings.index'), 'dashboard.settings.*', 'M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8Zm8 4a8 8 0 0 0-.2-1.8l2-1.5-2-3.4-2.4 1a8 8 0 0 0-3.1-1.8L14 2h-4l-.4 2.5a8 8 0 0 0-3.1 1.8l-2.4-1-2 3.4 2 1.5A8 8 0 0 0 4 12c0 .6.1 1.2.2 1.8l-2 1.5 2 3.4 2.4-1a8 8 0 0 0 3.1 1.8L10 22h4l.4-2.5a8 8 0 0 0 3.1-1.8l2.4 1 2-3.4-2-1.5c.1-.6.1-1.2.1-1.8Z'],
                            ]);
                        } elseif ($canManageWorkspace) {
                            $items = array_merge($items, [
                                [__('dashboard.nav.workspace_users'), route('dashboard.workspace-users.index'), 'dashboard.workspace-users.*', 'M16 11a4 4 0 1 0-8 0 4 4 0 0 0 8 0Zm-12 9a8 8 0 0 1 16 0H4Zm14-12a3 3 0 0 1 3 3v1h-2v-1a1 1 0 0 0-1-1V8Z'],
                                [__('dashboard.nav.sessions_short'), route('dashboard.sessions.index'), 'dashboard.sessions.*', 'M7 7h10v10H7V7Zm-4 3h2v4H3v-4Zm16 0h2v4h-2v-4ZM10 3h4v2h-4V3Zm0 16h4v2h-4v-2Z'],
                                [__('dashboard.nav.api_keys'), route('dashboard.api-keys.index'), 'dashboard.api-keys.*', 'M7 14a5 5 0 1 1 4.9-6H21v4h-3v3h-4v-3h-2.1A5 5 0 0 1 7 14Zm0-3a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z'],
                                [__('dashboard.nav.webhooks'), route('dashboard.webhooks.index'), 'dashboard.webhooks.*', 'M5 5h6v6H5V5Zm8 1h6v4h-6V6ZM5 15h6v4H5v-4Zm8-3h6v7h-6v-7Z'],
                                [__('dashboard.nav.messages'), route('dashboard.messages.index'), 'dashboard.messages.*', 'M4 5h16v10H7l-3 3V5Z'],
                                [__('dashboard.nav.audit_logs'), route('dashboard.audit.index'), 'dashboard.audit.*', 'M5 3h14v18H5V3Zm3 4h8v2H8V7Zm0 4h8v2H8v-2Zm0 4h5v2H8v-2Z'],
                            ]);
                        } else {
                            $items = array_merge($items, [
                                [__('dashboard.nav.sessions_short'), route('dashboard.sessions.index'), 'dashboard.sessions.*', 'M7 7h10v10H7V7Zm-4 3h2v4H3v-4Zm16 0h2v4h-2v-4ZM10 3h4v2h-4V3Zm0 16h4v2h-4v-2Z'],
                                [__('dashboard.nav.messages'), route('dashboard.messages.index'), 'dashboard.messages.*', 'M4 5h16v10H7l-3 3V5Z'],
                                [__('dashboard.nav.webhook_logs'), route('dashboard.webhooks.index'), 'dashboard.webhooks.*', 'M5 5h6v6H5V5Zm8 1h6v4h-6V6ZM5 15h6v4H5v-4Zm8-3h6v7h-6v-7Z'],
                            ]);
                        }
                        foreach (app(\App\Services\Plugins\PluginManager::class)->registry()->dashboardMenus() as $pluginItem) {
                            if (($pluginItem['permission'] ?? null) && ! $user?->can($pluginItem['permission'])) {
                                continue;
                            }
                            $items[] = [
                                $pluginItem['label'],
                                $pluginItem['route'],
                                $pluginItem['active'] ?? '',
                                $pluginItem['icon'] ?? 'M4 4h16v16H4V4Zm2 2v12h12V6H6Z',
                            ];
                        }
                        $locales = app(\App\Services\Plugins\PluginManager::class)->availableLocales();
                    @endphp
                    @foreach ($items as [$label, $href, $active, $icon])
                        <a href="{{ $href }}" class="flex items-center gap-3 rounded-md py-2 font-medium {{ $compactChrome ? 'justify-center px-2 group-hover/sidebar:justify-start group-hover/sidebar:px-3' : 'px-3' }} {{ request()->routeIs($active) ? 'bg-[#25d366]/10 text-[#128c42]' : 'text-slate-600 hover:bg-slate-100' }}" @if($compactChrome) data-sidebar-item title="{{ $label }}" @endif>
                            <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 fill-current" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                            <span class="{{ $compactChrome ? 'hidden group-hover/sidebar:inline' : '' }}" @if($compactChrome) data-sidebar-label @endif>{{ $label }}</span>
                        </a>
                    @endforeach
                </nav>
                @if ($compactChrome)
                    <div class="shrink-0 border-t border-slate-200 p-2">
                        <button type="button" class="flex w-full items-center justify-center gap-3 rounded-md px-2 py-2 text-sm font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-900 group-hover/sidebar:justify-start group-hover/sidebar:px-3" data-sidebar-toggle aria-expanded="false" title="Keep navigation open">
                            <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 fill-current" aria-hidden="true" data-sidebar-toggle-icon><path d="m9 5 7 7-7 7V5Z"/></svg>
                            <span class="hidden group-hover/sidebar:inline" data-sidebar-label data-sidebar-toggle-label>Keep navigation open</span>
                        </button>
                    </div>
                @endif
                <div class="shrink-0 border-t border-slate-200 p-3">
                    <details class="group rounded-lg border border-slate-200 bg-slate-50">
                        <summary class="flex cursor-pointer list-none items-center gap-3 py-3 {{ $compactChrome ? 'justify-center px-2 group-hover/sidebar:justify-start group-hover/sidebar:px-3' : 'px-3' }}" @if($compactChrome) data-sidebar-item @endif>
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-slate-900 text-sm font-semibold text-white">{{ strtoupper(substr($user?->name ?? 'U', 0, 1)) }}</div>
                            <div class="min-w-0 flex-1 {{ $compactChrome ? 'hidden group-hover/sidebar:block' : '' }}" @if($compactChrome) data-sidebar-label @endif>
                                <div class="truncate text-sm font-semibold text-slate-900">{{ $user?->name }}</div>
                                <div class="truncate text-xs text-slate-500">{{ $user?->email }}</div>
                                <div class="mt-1 text-xs font-medium text-[#128c42]">{{ $roleLabel }}</div>
                            </div>
                            <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current text-slate-400 group-open:rotate-180 {{ $compactChrome ? 'hidden group-hover/sidebar:block' : '' }}" aria-hidden="true" @if($compactChrome) data-sidebar-label @endif><path d="m7 10 5 5 5-5H7Z"/></svg>
                        </summary>
                        <div class="space-y-1 border-t border-slate-200 p-2 text-sm {{ $compactChrome ? 'hidden group-hover/sidebar:block' : '' }}" @if($compactChrome) data-sidebar-label @endif>
                            <a href="{{ route('dashboard.account.password') }}" class="flex items-center gap-2 rounded-md px-3 py-2 font-medium {{ request()->routeIs('dashboard.account.password*') ? 'bg-white text-[#128c42] shadow-sm' : 'text-slate-600 hover:bg-white' }}">
                                <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true"><path d="M7 10V8a5 5 0 0 1 10 0v2h1a2 2 0 0 1 2 2v8H4v-8a2 2 0 0 1 2-2h1Zm2 0h6V8a3 3 0 0 0-6 0v2Zm2 4v4h2v-4h-2Z"/></svg>
                                {{ __('dashboard.account.change_password') }}
                            </a>
                            <a href="{{ route('dashboard.account.two-factor') }}" class="flex items-center gap-2 rounded-md px-3 py-2 font-medium {{ request()->routeIs('dashboard.account.two-factor*') ? 'bg-white text-[#128c42] shadow-sm' : 'text-slate-600 hover:bg-white' }}">
                                <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 9.4 8 10.8 4.6-1.4 8-5.8 8-10.8V5l-8-3Zm0 2.2 6 2.3V11c0 3.8-2.4 7.2-6 8.6-3.6-1.4-6-4.8-6-8.6V6.5l6-2.3Zm-1 5.8h2v2h-2v-2Zm0 4h2v4h-2v-4Z"/></svg>
                                {{ __('dashboard.account.setup_2fa') }}
                            </a>
                            <a href="{{ route('dashboard.account.passkeys') }}" class="flex items-center gap-2 rounded-md px-3 py-2 font-medium {{ request()->routeIs('dashboard.account.passkeys*') ? 'bg-white text-[#128c42] shadow-sm' : 'text-slate-600 hover:bg-white' }}">
                                <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true"><path d="M7 14a5 5 0 1 1 4.9-6H21v4h-3v3h-4v-3h-2.1A5 5 0 0 1 7 14Zm0-3a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/></svg>
                                {{ __('dashboard.account.passkeys') }}
                            </a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left font-medium text-slate-600 hover:bg-white">
                                    <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true"><path d="M4 4h9v2H6v12h7v2H4V4Zm12.6 4.6L20 12l-3.4 3.4-1.4-1.4 1-1H10v-2h6.2l-1-1 1.4-1.4Z"/></svg>
                                    {{ __('dashboard.account.logout') }}
                                </button>
                            </form>
                        </div>
                    </details>
                </div>
                </div>
            </aside>
            <main class="min-w-0 flex-1 overflow-y-auto">
                @unless ($compactChrome)
                <header class="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6" data-dashboard-header>
                    <div>
                        <div class="text-sm text-slate-500">{{ $workspace->name ?? 'LaraWA' }}</div>
                        <h1 class="text-lg font-semibold">{{ $title ?? 'Dashboard' }}</h1>
                    </div>
                    <div class="flex items-center gap-2">
                        @unless (request()->routeIs('dashboard.workspace.select'))
                            <a href="{{ route('dashboard.workspace.select') }}" class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('dashboard.chrome.change_workspace') }}</a>
                        @endunless
                        @if (count($locales) > 1)
                            <form method="POST" action="{{ route('dashboard.account.language.update') }}" class="flex items-center gap-2" data-auto-submit>
                                @csrf
                                @method('PATCH')
                                <label class="sr-only" for="dashboard_locale">{{ __('dashboard.language.label') }}</label>
                                <select id="dashboard_locale" name="dashboard_locale" class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700">
                                    @foreach ($locales as $locale => $definition)
                                        <option value="{{ $locale }}" @selected(app()->getLocale() === $locale)>{{ $definition['native'] }}</option>
                                    @endforeach
                                </select>
                                <button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50" data-auto-submit-fallback>{{ __('dashboard.language.apply') }}</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('logout') }}" class="lg:hidden">
                            @csrf
                            <button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('dashboard.account.logout') }}</button>
                        </form>
                    </div>
                </header>
                @else
                <header class="flex h-14 items-center justify-between border-b border-slate-200 bg-white px-4 lg:hidden" data-mobile-session-header>
                    <div class="min-w-0">
                        <div class="truncate text-xs text-slate-500">{{ $workspace->name ?? 'LaraWA' }}</div>
                        <h1 class="truncate text-sm font-semibold">{{ $title ?? 'Dashboard' }}</h1>
                    </div>
                    <div class="ml-3 flex shrink-0 items-center gap-2">
                        <a href="{{ route('dashboard.workspace.select') }}" class="rounded-md border border-slate-300 px-2.5 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">{{ __('dashboard.chrome.change_workspace') }}</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="rounded-md border border-slate-300 px-2.5 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">{{ __('dashboard.account.logout') }}</button>
                        </form>
                    </div>
                </header>
                @endunless
                <div class="{{ $compactChrome ? 'p-3' : 'p-4 sm:p-6' }}" @if($compactChrome) data-compact-content-wrapper @endif>
                    @if (session('status'))
                        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
                    @endif
                    @if (session('error'))
                        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
                    @endif
                    @if ($errors->any())
                        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
                    @endif
                    {{ $slot }}
                </div>
            </main>
        </div>
    @else
        {{ $slot }}
    @endif
</body>
</html>

<x-layouts.app title="Setup">
    @php
        $preview = $preview ?? false;
        $dbConnection = old('db_connection', env('DB_CONNECTION', config('database.default', 'sqlite')));
        $databaseConfig = config("database.connections.{$dbConnection}", []);
        $redisConfig = config('database.redis.default', []);
        $s3Config = config('filesystems.disks.s3', []);
        $filesystemDisk = old('filesystem_disk', config('filesystems.default', 'local'));
        $usesRedis = old('use_redis', in_array(env('CACHE_STORE', config('cache.default')), ['redis'], true) || in_array(env('QUEUE_CONNECTION', config('queue.default')), ['redis'], true) || in_array(env('SESSION_DRIVER', config('session.driver')), ['redis'], true));
        $timezone = old('app_timezone', config('app.timezone', 'UTC'));
        $cloudflareFlexibleSsl = old('cloudflare_flexible_ssl', config('app.force_https') && env('TRUSTED_PROXIES') === '*');
        $timezones = DateTimeZone::listIdentifiers();
        $steps = ['Application', 'Database', 'Redis', 'Storage', 'Workspace', 'Preview'];
    @endphp

    <div class="min-h-screen bg-slate-950 px-4 py-8">
        <div class="mx-auto w-full max-w-5xl rounded-lg bg-white p-6 shadow-2xl sm:p-8" data-setup-wizard @if ($preview) data-setup-preview="true" @endif>
            <div class="mb-8 flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    @include('partials.brand-lockup')
                    <div>
                        <h1 class="mt-3 text-xl font-semibold">Initialize LaraWA</h1>
                        <p class="text-sm text-slate-500">Configure services, review, then execute installation</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 text-xs font-semibold" aria-label="Setup steps">
                    @foreach ($steps as $index => $step)
                        <button type="button" data-wizard-step-button="{{ $index }}" class="rounded-md border border-slate-200 px-3 py-2 text-slate-500 disabled:cursor-not-allowed">
                            {{ $index + 1 }}. {{ $step }}
                        </button>
                    @endforeach
                </div>
            </div>

            @if ($preview)
                <div class="mb-5 rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
                    Preview mode is read-only. It does not check installation state, submit changes, or write database, session, or cache data.
                </div>
            @endif

            @unless ($environmentWritable || $preview)
                <div class="mb-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    The environment file is not writable. Make the LaraWA `.env` file writable for installation, then lock it down after setup.
                </div>
            @endunless

            @if (isset($errors) && $errors->any())
                <div class="mb-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
            @endif

            <form method="{{ $preview ? 'GET' : 'POST' }}" action="{{ $preview ? '#' : route('setup.store', [], false) }}" class="space-y-6" data-setup-form data-progress-url="{{ route('setup.progress', ['id' => '__ID__'], false) }}">
                @unless ($preview)
                    @csrf
                @endunless
                <input type="hidden" name="setup_progress_id" value="{{ (string) Illuminate\Support\Str::uuid() }}" data-setup-progress-id>

                <fieldset @disabled($preview)>
                    <section class="space-y-5" data-wizard-step-panel="0">
                        <div>
                            <h2 class="text-lg font-semibold">Application Setting</h2>
                            <p class="text-sm text-slate-500">Set the public URL, proxy mode, worker connection, and webhook limits.</p>
                        </div>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium text-slate-700">Public URL</span>
                                <input name="app_url" type="url" value="{{ old('app_url', config('app.url') ?: url('/')) }}" required class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium text-slate-700">Timezone</span>
                                <select name="app_timezone" required class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                                    @foreach ($timezones as $value)
                                        <option value="{{ $value }}" @selected($timezone === $value)>{{ $value }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="flex items-start gap-3 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 sm:col-span-2">
                                <input name="cloudflare_flexible_ssl" type="checkbox" value="1" @checked($cloudflareFlexibleSsl) class="mt-1 rounded border-slate-300 text-[#25d366]">
                                <span>
                                    <span class="block font-medium text-slate-800">Application runs behind Cloudflare proxy with Flexible SSL</span>
                                    <span class="mt-1 block text-slate-500">Enable this only when Cloudflare terminates HTTPS and forwards plain HTTP to LaraWA. The installer will set APP_FORCE_HTTPS=true and TRUSTED_PROXIES=*.</span>
                                </span>
                            </label>
                            <div class="space-y-5 rounded-md border border-slate-200 bg-slate-50 p-4 sm:col-span-2">
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-800">WA Worker</h3>
                                </div>
                                <div class="grid gap-5 sm:grid-cols-2">
                                    <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Worker URL</span><input name="worker_url" type="url" value="{{ old('worker_url', config('larawa.worker_url')) }}" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                                    <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Worker callback URL</span><input name="worker_callback_url" type="url" value="{{ old('worker_callback_url', config('larawa.worker_callback_url')) }}" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                                </div>
                                <label class="block">
                                    <span class="mb-1 block text-sm font-medium text-slate-700">Worker internal token</span>
                                    <div class="grid gap-2 sm:grid-cols-[1fr_auto]">
                                        <input name="worker_token" type="password" value="{{ old('worker_token', config('larawa.worker_token')) }}" minlength="32" required data-worker-token class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                                        <button type="button" data-generate-worker-token class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Generate</button>
                                    </div>
                                </label>
                            </div>
                            <div class="grid gap-5 sm:col-span-2 sm:grid-cols-3">
                                <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">API rate limit / minute</span><input name="api_rate_limit_per_minute" type="number" min="1" max="100000" value="{{ old('api_rate_limit_per_minute', config('larawa.api_rate_limit_per_minute', 120)) }}" required class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                                <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Webhook timeout seconds</span><input name="webhook_timeout" type="number" min="1" max="300" value="{{ old('webhook_timeout', config('larawa.webhook_timeout', 10)) }}" required class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                                <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Webhook retry attempts</span><input name="webhook_retry_attempts" type="number" min="0" max="100" value="{{ old('webhook_retry_attempts', config('larawa.webhook_retry_attempts', 3)) }}" required class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                            </div>
                        </div>
                    </section>

                    <section class="hidden space-y-5" data-wizard-step-panel="1">
                        <div>
                            <h2 class="text-lg font-semibold">Database</h2>
                            <p class="text-sm text-slate-500">Use existing `.env` values or configure the database here.</p>
                        </div>
                        <div class="grid gap-5 sm:grid-cols-3">
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium text-slate-700">Connection</span>
                                <select name="db_connection" required data-db-connection class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                                    @foreach (['sqlite' => 'SQLite', 'pgsql' => 'PostgreSQL', 'mysql' => 'MySQL / MariaDB'] as $value => $label)
                                        <option value="{{ $value }}" @selected($dbConnection === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <div class="space-y-5 rounded-md border border-slate-200 bg-slate-50 p-4" data-db-fields="sqlite">
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium text-slate-700">SQLite database path</span>
                                <input name="sqlite_database" value="{{ old('sqlite_database', config('database.connections.sqlite.database', 'database/database.sqlite')) }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                            </label>
                        </div>

                        <div class="space-y-5 rounded-md border border-slate-200 bg-slate-50 p-4" data-db-fields="mysql pgsql">
                            <div class="grid gap-5 sm:grid-cols-3">
                                <label class="block">
                                    <span class="mb-1 block text-sm font-medium text-slate-700">Host</span>
                                    <input name="db_host" value="{{ old('db_host', $databaseConfig['host'] ?? '127.0.0.1') }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                                </label>
                                <label class="block">
                                    <span class="mb-1 block text-sm font-medium text-slate-700">Port</span>
                                    <input name="db_port" type="number" min="1" max="65535" value="{{ old('db_port', $databaseConfig['port'] ?? ($dbConnection === 'pgsql' ? 5432 : 3306)) }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                                </label>
                                <label class="block">
                                    <span class="mb-1 block text-sm font-medium text-slate-700">Database</span>
                                    <input name="db_database" value="{{ old('db_database', $dbConnection === 'sqlite' ? 'larawa' : ($databaseConfig['database'] ?? 'larawa')) }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                                </label>
                            </div>
                            <div class="grid gap-5 sm:grid-cols-2">
                                <label class="block">
                                    <span class="mb-1 block text-sm font-medium text-slate-700">Username</span>
                                    <input name="db_username" value="{{ old('db_username', $databaseConfig['username'] ?? '') }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                                </label>
                                <label class="block">
                                    <span class="mb-1 block text-sm font-medium text-slate-700">Password</span>
                                    <input name="db_password" type="password" value="{{ old('db_password', $databaseConfig['password'] ?? '') }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                                </label>
                            </div>
                        </div>

                        <div class="space-y-5 rounded-md border border-slate-200 bg-slate-50 p-4" data-db-fields="pgsql">
                            <label class="block max-w-sm">
                                <span class="mb-1 block text-sm font-medium text-slate-700">SSL mode</span>
                                <select name="db_sslmode" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                                    @foreach (['prefer', 'require', 'disable', 'allow', 'verify-ca', 'verify-full'] as $mode)
                                        <option value="{{ $mode }}" @selected(old('db_sslmode', config('database.connections.pgsql.sslmode', 'prefer')) === $mode)>{{ $mode }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </section>

                    <section class="hidden space-y-5" data-wizard-step-panel="2">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-lg font-semibold">Redis</h2>
                                <p class="text-sm text-slate-500">Optional cache, queue, and session backend.</p>
                            </div>
                            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                                <input name="use_redis" type="checkbox" value="1" @checked($usesRedis) data-redis-toggle class="rounded border-slate-300 text-[#25d366]">
                                Use Redis
                            </label>
                        </div>
                        <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-600" data-redis-disabled>
                            Redis is disabled. LaraWA will use database-backed cache, queues, and sessions.
                        </div>
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-4" data-redis-fields>
                            <div class="grid gap-5 sm:grid-cols-4">
                                <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Host</span><input name="redis_host" value="{{ old('redis_host', $redisConfig['host'] ?? '127.0.0.1') }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                                <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Port</span><input name="redis_port" type="number" min="1" max="65535" value="{{ old('redis_port', $redisConfig['port'] ?? 6379) }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                                <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Username</span><input name="redis_username" value="{{ old('redis_username', $redisConfig['username'] ?? '') }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                                <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Password</span><input name="redis_password" type="password" value="{{ old('redis_password', $redisConfig['password'] ?? '') }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                            </div>
                        </div>
                    </section>

                    <section class="hidden space-y-5" data-wizard-step-panel="3">
                        <div>
                            <h2 class="text-lg font-semibold">Storage</h2>
                            <p class="text-sm text-slate-500">Choose local media storage or S3-compatible storage.</p>
                        </div>
                        <div class="grid gap-5 sm:grid-cols-3">
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium text-slate-700">Disk</span>
                                <select name="filesystem_disk" required data-storage-disk class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                                    <option value="local" @selected($filesystemDisk === 'local')>Local</option>
                                    <option value="s3" @selected($filesystemDisk === 's3')>S3 compatible</option>
                                </select>
                            </label>
                        </div>
                        <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-600" data-storage-fields="local">
                            Media files will be stored on the local filesystem disk.
                        </div>
                        <div class="space-y-5 rounded-md border border-slate-200 bg-slate-50 p-4" data-storage-fields="s3">
                            <div class="grid gap-5 sm:grid-cols-2">
                                <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Access key</span><input name="aws_access_key_id" value="{{ old('aws_access_key_id', $s3Config['key'] ?? '') }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                                <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Secret key</span><input name="aws_secret_access_key" type="password" value="{{ old('aws_secret_access_key', $s3Config['secret'] ?? '') }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                            </div>
                            <div class="grid gap-5 sm:grid-cols-3">
                                <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Region</span><input name="aws_default_region" value="{{ old('aws_default_region', $s3Config['region'] ?? 'us-east-1') }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                                <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Bucket</span><input name="aws_bucket" value="{{ old('aws_bucket', $s3Config['bucket'] ?? '') }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                                <label class="flex items-end gap-2 pb-2 text-sm font-medium text-slate-700"><input name="aws_use_path_style_endpoint" type="checkbox" value="1" @checked(old('aws_use_path_style_endpoint', $s3Config['use_path_style_endpoint'] ?? true)) class="rounded border-slate-300 text-[#25d366]">Path-style endpoint</label>
                            </div>
                            <div class="grid gap-5 sm:grid-cols-2">
                                <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Public S3 URL</span><input name="aws_url" value="{{ old('aws_url', $s3Config['url'] ?? '') }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                                <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">S3 endpoint</span><input name="aws_endpoint" value="{{ old('aws_endpoint', $s3Config['endpoint'] ?? '') }}" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                            </div>
                        </div>
                    </section>

                    <section class="hidden space-y-5" data-wizard-step-panel="4">
                        <div>
                            <h2 class="text-lg font-semibold">Workspace Init + Site Admin Setup</h2>
                            <p class="text-sm text-slate-500">Create the first workspace and site administrator.</p>
                        </div>
                        <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Workspace name</span><input name="workspace_name" value="{{ old('workspace_name', config('larawa.default_workspace')) }}" required class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Admin name</span><input name="name" value="{{ old('name') }}" required class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                            <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Admin email</span><input name="email" type="email" value="{{ old('email') }}" required class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                        </div>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Password</span><input name="password" type="password" minlength="8" required class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                            <label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Confirm password</span><input name="password_confirmation" type="password" minlength="8" required class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20"></label>
                        </div>
                    </section>
                </fieldset>

                <section class="hidden space-y-5" data-wizard-step-panel="5">
                    <div>
                        <h2 class="text-lg font-semibold">Preview</h2>
                        <p class="text-sm text-slate-500">Review the installation settings before execution.</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-setup-summary></div>
                    <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-install-ready>
                        Execute will test services, write `.env`, migrate, create the site admin, and disable setup.
                    </div>
                    <div class="hidden rounded-md border border-sky-200 bg-sky-50 px-4 py-4 text-sm text-sky-900" data-install-progress>
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="font-semibold" data-install-progress-title>Installing LaraWA...</div>
                                <div class="mt-1 text-sky-700" data-install-progress-message>Waiting for installer to start.</div>
                            </div>
                            <div class="text-sm font-semibold tabular-nums" data-install-progress-percent>0%</div>
                        </div>
                        <div class="mt-4 h-2 overflow-hidden rounded-full bg-sky-100">
                            <div class="h-full w-0 rounded-full bg-[#25d366] transition-all duration-300" data-install-progress-bar></div>
                        </div>
                        <ol class="mt-4 space-y-2" data-install-progress-steps>
                            <li class="flex items-center gap-2 text-sky-700" data-install-step="lock"><span class="h-2 w-2 rounded-full bg-sky-300"></span><span>Acquire installer lock</span></li>
                            <li class="flex items-center gap-2 text-sky-700" data-install-step="environment"><span class="h-2 w-2 rounded-full bg-sky-300"></span><span>Check and write environment settings</span></li>
                            <li class="flex items-center gap-2 text-sky-700" data-install-step="database"><span class="h-2 w-2 rounded-full bg-sky-300"></span><span>Test and prepare database</span></li>
                            <li class="flex items-center gap-2 text-sky-700" data-install-step="redis"><span class="h-2 w-2 rounded-full bg-sky-300"></span><span>Verify Redis settings</span></li>
                            <li class="flex items-center gap-2 text-sky-700" data-install-step="migrations"><span class="h-2 w-2 rounded-full bg-sky-300"></span><span>Run migrations</span></li>
                            <li class="flex items-center gap-2 text-sky-700" data-install-step="seeders"><span class="h-2 w-2 rounded-full bg-sky-300"></span><span>Run seeders</span></li>
                            <li class="flex items-center gap-2 text-sky-700" data-install-step="admin"><span class="h-2 w-2 rounded-full bg-sky-300"></span><span>Create workspace and site admin</span></li>
                            <li class="flex items-center gap-2 text-sky-700" data-install-step="storage"><span class="h-2 w-2 rounded-full bg-sky-300"></span><span>Create storage link</span></li>
                            <li class="flex items-center gap-2 text-sky-700" data-install-step="config"><span class="h-2 w-2 rounded-full bg-sky-300"></span><span>Clear/cache Laravel configuration</span></li>
                            <li class="flex items-center gap-2 text-sky-700" data-install-step="verification"><span class="h-2 w-2 rounded-full bg-sky-300"></span><span>Verify environment file, tables, and admin</span></li>
                            <li class="flex items-center gap-2 text-sky-700" data-install-step="complete"><span class="h-2 w-2 rounded-full bg-sky-300"></span><span>Complete setup</span></li>
                        </ol>
                    </div>
                    <div class="hidden rounded-md border border-emerald-200 bg-emerald-50 px-4 py-4 text-sm text-emerald-900" data-install-complete>
                        <div class="font-semibold">Installation complete</div>
                        <p class="mt-1 text-emerald-800">LaraWA is ready. Continue to the login page to sign in with the site administrator account.</p>
                        <a href="{{ route('login', [], false) }}" data-install-login class="mt-4 inline-flex w-full items-center justify-center rounded-md bg-[#25d366] px-4 py-2.5 font-semibold text-white hover:bg-[#1eb858] sm:w-auto">Go to login</a>
                    </div>
                    <button data-install-submit class="w-full rounded-md bg-[#25d366] px-4 py-2.5 font-semibold text-white hover:bg-[#1eb858] disabled:cursor-not-allowed disabled:bg-slate-300" @disabled(! $environmentWritable || $preview)>{{ $preview ? 'Preview only' : 'Execute installation' }}</button>
                </section>

                <div class="flex items-center justify-between border-t border-slate-200 pt-5" data-wizard-navigation>
                    <button type="button" data-wizard-back class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Back</button>
                    <button type="button" data-wizard-next class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Next</button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>

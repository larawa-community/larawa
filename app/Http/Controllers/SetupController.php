<?php

namespace App\Http\Controllers;

use App\Services\EnvironmentFile;
use App\Services\InitialSetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class SetupController extends Controller
{
    public function show(InitialSetup $setup, EnvironmentFile $environment): View
    {
        abort_unless($setup->needed(), 404);

        return view('auth.setup', [
            'environmentWritable' => $this->environmentWritable($environment),
            'preview' => false,
        ]);
    }

    public function preview(EnvironmentFile $environment): View
    {
        abort_if(app()->environment('production'), 404);

        return view('auth.setup', [
            'environmentWritable' => $this->environmentWritable($environment),
            'preview' => true,
        ]);
    }

    public function progress(string $id): JsonResponse
    {
        abort_unless(preg_match('/^[a-f0-9-]{36}$/i', $id) === 1, 404);

        return response()->json(Cache::get($this->progressCacheKey($id), [
            'step' => 'waiting',
            'message' => 'Waiting for installer to start.',
            'percent' => 0,
            'complete' => false,
            'failed' => false,
        ]));
    }

    public function store(Request $request, InitialSetup $setup, EnvironmentFile $environment): RedirectResponse|JsonResponse
    {
        abort_unless($setup->needed(), 404);

        $progressId = $this->validProgressId((string) $request->input('setup_progress_id'))
            ? (string) $request->input('setup_progress_id')
            : null;
        if ($progressId) {
            $this->writeProgress($progressId, 'validation', 'Validating setup form data.', 2);
        }

        $request->merge([
            'app_url' => $request->filled('app_url') ? $request->input('app_url') : (config('app.url') ?: $request->getSchemeAndHttpHost()),
            'app_timezone' => $request->filled('app_timezone') ? $request->input('app_timezone') : config('app.timezone', 'UTC'),
            'db_connection' => $request->filled('db_connection') ? $request->input('db_connection') : config('database.default', 'sqlite'),
        ]);

        try {
            $data = $request->validate([
                'app_url' => ['required', 'url', 'max:255'],
                'app_timezone' => ['required', 'timezone'],
                'db_connection' => ['required', Rule::in(['sqlite', 'mysql', 'pgsql'])],
                'sqlite_database' => ['required_if:db_connection,sqlite', 'nullable', 'string', 'max:500'],
                'db_host' => ['required_unless:db_connection,sqlite', 'nullable', 'string', 'max:255'],
                'db_port' => ['required_unless:db_connection,sqlite', 'nullable', 'integer', 'between:1,65535'],
                'db_database' => ['required_unless:db_connection,sqlite', 'nullable', 'string', 'max:255'],
                'db_username' => ['nullable', 'string', 'max:255'],
                'db_password' => ['nullable', 'string', 'max:255'],
                'db_sslmode' => ['nullable', Rule::in(['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'])],
                'use_redis' => ['nullable', 'boolean'],
                'redis_host' => ['required_if:use_redis,1', 'nullable', 'string', 'max:255'],
                'redis_port' => ['required_if:use_redis,1', 'nullable', 'integer', 'between:1,65535'],
                'redis_username' => ['nullable', 'string', 'max:255'],
                'redis_password' => ['nullable', 'string', 'max:255'],
                'filesystem_disk' => ['required', Rule::in(['local', 's3'])],
                'aws_access_key_id' => ['required_if:filesystem_disk,s3', 'nullable', 'string', 'max:255'],
                'aws_secret_access_key' => ['required_if:filesystem_disk,s3', 'nullable', 'string', 'max:255'],
                'aws_default_region' => ['required_if:filesystem_disk,s3', 'nullable', 'string', 'max:255'],
                'aws_bucket' => ['required_if:filesystem_disk,s3', 'nullable', 'string', 'max:255'],
                'aws_url' => ['nullable', 'url', 'max:500'],
                'aws_endpoint' => ['nullable', 'url', 'max:500'],
                'aws_use_path_style_endpoint' => ['nullable', 'boolean'],
                'worker_url' => ['required', 'url', 'max:500'],
                'worker_token' => ['required', 'string', 'min:32', 'max:255'],
                'worker_callback_url' => ['required', 'url', 'max:500'],
                'api_rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:100000'],
                'webhook_timeout' => ['required', 'integer', 'min:1', 'max:300'],
                'webhook_retry_attempts' => ['required', 'integer', 'min:0', 'max:100'],
                'cloudflare_flexible_ssl' => ['nullable', 'boolean'],
                'workspace_name' => ['required', 'string', 'max:120'],
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'setup_progress_id' => ['nullable', 'uuid'],
            ]);
        } catch (ValidationException $exception) {
            if ($progressId) {
                $this->writeProgress($progressId, 'failed', $exception->validator->errors()->first() ?: 'Setup validation failed.', 2, failed: true);
            }

            throw $exception;
        }
        $data['use_redis'] = $request->boolean('use_redis');
        $data['aws_use_path_style_endpoint'] = $request->boolean('aws_use_path_style_endpoint');
        $data['cloudflare_flexible_ssl'] = $request->boolean('cloudflare_flexible_ssl');
        unset($data['setup_progress_id']);

        try {
            $user = $setup->install($data, $environment, $this->progressReporter($progressId));
        } catch (Throwable $exception) {
            if ($progressId) {
                $this->writeProgress($progressId, 'failed', $exception->getMessage(), $this->currentProgressPercent($progressId), failed: true);
            }

            throw ValidationException::withMessages([
                'setup' => $exception->getMessage(),
            ]);
        }

        if ($this->wantsInstallerJson($request)) {
            return response()->json([
                'installed' => true,
                'message' => 'LaraWA setup is complete.',
                'progress_id' => $progressId,
                'redirect' => route('login', [], false),
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'LaraWA setup is complete.');
    }

    private function wantsInstallerJson(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax();
    }

    private function validProgressId(string $id): bool
    {
        return preg_match('/^[a-f0-9-]{36}$/i', $id) === 1;
    }

    private function progressReporter(?string $id): callable
    {
        return function (string $step, string $message, int $percent) use ($id): void {
            if ($id === null) {
                return;
            }

            $this->writeProgress($id, $step, $message, $percent, complete: $step === 'complete');
        };
    }

    private function writeProgress(string $id, string $step, string $message, int $percent, bool $complete = false, bool $failed = false): void
    {
        Cache::put($this->progressCacheKey($id), [
            'step' => $step,
            'message' => $message,
            'percent' => max(0, min(100, $percent)),
            'complete' => $complete,
            'failed' => $failed,
        ], now()->addMinutes(30));
    }

    private function currentProgressPercent(string $id): int
    {
        return (int) (Cache::get($this->progressCacheKey($id), ['percent' => 0])['percent'] ?? 0);
    }

    private function progressCacheKey(string $id): string
    {
        return "larawa:setup-progress:{$id}";
    }

    private function environmentWritable(EnvironmentFile $environment): bool
    {
        try {
            $environment->assertWritable();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}

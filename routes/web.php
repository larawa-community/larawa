<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Dashboard\AccountSecurityController;
use App\Http\Controllers\Dashboard\ApiKeyController;
use App\Http\Controllers\Dashboard\CloudConversationController;
use App\Http\Controllers\Dashboard\CloudTemplateController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\LogController;
use App\Http\Controllers\Dashboard\MarketplaceController;
use App\Http\Controllers\Dashboard\SessionController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\UserController;
use App\Http\Controllers\Dashboard\WebhookController;
use App\Http\Controllers\Dashboard\WebhookDeliveryController;
use App\Http\Controllers\Dashboard\WorkspaceController;
use App\Http\Controllers\Dashboard\WorkspaceSelectionController;
use App\Http\Controllers\Dashboard\WorkspaceUserController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MessageMediaController;
use App\Http\Controllers\SetupController;
use App\Services\InitialSetup;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', fn (InitialSetup $setup) => redirect()->route($setup->needed() ? 'setup' : 'dashboard'));

Route::get('/healthz', HealthController::class)->name('healthz');
Route::get('/docs', [DocsController::class, 'swagger'])->name('docs.swagger');
Route::get('/docs/openapi.yaml', [DocsController::class, 'openApi'])->name('docs.openapi');
Route::post('/locale', [AuthController::class, 'updateLocale'])->name('locale.update');

Route::get('/setup', [SetupController::class, 'show'])->name('setup');
Route::get('/setup/progress/{id}', [SetupController::class, 'progress'])->name('setup.progress');
Route::post('/setup', [SetupController::class, 'store'])->name('setup.store');

Route::prefix('_preview')
    ->name('preview.')
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        ValidateCsrfToken::class,
    ])
    ->group(function () {
        Route::get('/setup', [SetupController::class, 'preview'])->name('setup');
    });

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');
    Route::get('/login/two-factor', [AuthController::class, 'showTwoFactorChallenge'])->name('login.two-factor');
    Route::post('/login/two-factor', [AuthController::class, 'twoFactorChallenge'])->name('login.two-factor.store');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'user.enabled'])->prefix('dashboard')->name('dashboard')->group(function () {
    Route::get('/workspace/select', [WorkspaceSelectionController::class, 'show'])->name('.workspace.select');
    Route::post('/workspace/select', [WorkspaceSelectionController::class, 'store'])->name('.workspace.select.store');

    Route::middleware('dashboard.workspace')->group(function () {
        Route::get('/', DashboardController::class);

        Route::redirect('/account/security', '/dashboard/account/password')->name('.account.security');
        Route::get('/account/password', [AccountSecurityController::class, 'password'])->name('.account.password');
        Route::patch('/account/password', [AccountSecurityController::class, 'updatePassword'])->name('.account.password.update');
        Route::patch('/account/language', [AccountSecurityController::class, 'updateLanguage'])->name('.account.language.update');
        Route::get('/account/passkeys', [AccountSecurityController::class, 'passkeys'])->name('.account.passkeys');
        Route::post('/account/passkeys/confirm-password', [AccountSecurityController::class, 'confirmPasskeyAction'])->name('.account.passkeys.confirm-password');
        Route::get('/account/two-factor', [AccountSecurityController::class, 'twoFactor'])->name('.account.two-factor');
        Route::post('/account/two-factor', [AccountSecurityController::class, 'startTwoFactor'])->name('.account.two-factor.start');
        Route::patch('/account/two-factor', [AccountSecurityController::class, 'confirmTwoFactor'])->name('.account.two-factor.confirm');
        Route::delete('/account/two-factor', [AccountSecurityController::class, 'disableTwoFactor'])->name('.account.two-factor.disable');
        Route::post('/account/two-factor/recovery-codes', [AccountSecurityController::class, 'regenerateRecoveryCodes'])->name('.account.two-factor.recovery-codes');

        Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('.workspaces.index');
        Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('.workspaces.store');
        Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show'])->name('.workspaces.show');
        Route::patch('/workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('.workspaces.update');
        Route::patch('/workspaces/{workspace}/suspension', [WorkspaceController::class, 'toggleSuspension'])->name('.workspaces.suspension');
        Route::post('/workspaces/{workspace}/admins', [WorkspaceController::class, 'assignAdmin'])->name('.workspaces.admins.store');
        Route::delete('/workspaces/{workspace}', [WorkspaceController::class, 'destroy'])->name('.workspaces.destroy');

        Route::get('/users', [UserController::class, 'index'])->name('.users.index');
        Route::post('/users', [UserController::class, 'store'])->name('.users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('.users.show');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('.users.update');
        Route::patch('/users/{user}/password', [UserController::class, 'resetPassword'])->name('.users.password');
        Route::patch('/users/{user}/disabled', [UserController::class, 'toggleDisabled'])->name('.users.disabled');
        Route::post('/users/{user}/workspaces', [UserController::class, 'assignWorkspace'])->name('.users.workspaces.store');
        Route::delete('/users/{user}/workspaces/{membershipWorkspace}', [UserController::class, 'removeWorkspace'])->name('.users.workspaces.destroy');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('.users.destroy');

        Route::get('/workspace-users', [WorkspaceUserController::class, 'index'])->name('.workspace-users.index');
        Route::post('/workspace-users', [WorkspaceUserController::class, 'store'])->name('.workspace-users.store');
        Route::get('/workspace-users/{user}', [WorkspaceUserController::class, 'show'])->name('.workspace-users.show');
        Route::patch('/workspace-users/{user}', [WorkspaceUserController::class, 'update'])->name('.workspace-users.update');
        Route::delete('/workspace-users/{user}', [WorkspaceUserController::class, 'destroy'])->name('.workspace-users.destroy');

        Route::get('/sessions', [SessionController::class, 'index'])->name('.sessions.index');
        Route::post('/sessions', [SessionController::class, 'store'])->name('.sessions.store');
        Route::patch('/sessions/{session}', [SessionController::class, 'update'])->name('.sessions.update');
        Route::get('/sessions/{session}', [SessionController::class, 'show'])->name('.sessions.show');
        Route::get('/sessions/{session}/snapshot', [SessionController::class, 'snapshot'])->name('.sessions.snapshot');
        Route::post('/sessions/{session}/refresh', [SessionController::class, 'refresh'])->name('.sessions.refresh');
        Route::post('/sessions/{session}/test-message', [SessionController::class, 'sendTestMessage'])->name('.sessions.test-message');
        Route::post('/sessions/{session}/disconnect', [SessionController::class, 'disconnect'])->name('.sessions.disconnect');
        Route::post('/sessions/{session}/logout', [SessionController::class, 'logout'])->name('.sessions.logout');
        Route::delete('/sessions/{session}', [SessionController::class, 'destroy'])->name('.sessions.destroy');

        Route::get('/sessions/{session}/conversations', [CloudConversationController::class, 'index'])->name('.sessions.conversations.index');
        Route::get('/sessions/{session}/conversations/{conversation}', [CloudConversationController::class, 'show'])->name('.sessions.conversations.show');
        Route::get('/sessions/{session}/cloud-settings', [CloudConversationController::class, 'settings'])->name('.sessions.cloud-settings');
        Route::post('/sessions/{session}/conversations/{conversation}/messages/text', [CloudConversationController::class, 'reply'])->name('.sessions.conversations.messages.text');
        Route::post('/sessions/{session}/conversations/{conversation}/messages/template', [CloudConversationController::class, 'sendTemplate'])->name('.sessions.conversations.messages.template');
        Route::get('/sessions/{session}/templates', [CloudTemplateController::class, 'index'])->name('.sessions.templates.index');
        Route::get('/sessions/{session}/templates/create', [CloudTemplateController::class, 'create'])->name('.sessions.templates.create');
        Route::post('/sessions/{session}/templates/sync', [CloudTemplateController::class, 'sync'])->name('.sessions.templates.sync');
        Route::post('/sessions/{session}/templates', [CloudTemplateController::class, 'store'])->name('.sessions.templates.store');
        Route::get('/sessions/{session}/templates/{template}', [CloudTemplateController::class, 'show'])->name('.sessions.templates.show');
        Route::get('/sessions/{session}/templates/{template}/edit', [CloudTemplateController::class, 'edit'])->name('.sessions.templates.edit');
        Route::patch('/sessions/{session}/templates/{template}', [CloudTemplateController::class, 'update'])->name('.sessions.templates.update');
        Route::post('/sessions/{session}/templates/{template}/send', [CloudTemplateController::class, 'send'])->name('.sessions.templates.send');

        Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('.api-keys.index');
        Route::post('/api-keys', [ApiKeyController::class, 'store'])->name('.api-keys.store');
        Route::patch('/api-keys/{apiKey}', [ApiKeyController::class, 'update'])->name('.api-keys.update');
        Route::post('/api-keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate'])->name('.api-keys.rotate');
        Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('.api-keys.destroy');

        Route::get('/webhooks', [WebhookController::class, 'index'])->name('.webhooks.index');
        Route::post('/webhooks', [WebhookController::class, 'store'])->name('.webhooks.store');
        Route::patch('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('.webhooks.update');
        Route::patch('/webhooks/{webhook}/toggle', [WebhookController::class, 'toggle'])->name('.webhooks.toggle');
        Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])->name('.webhooks.test');
        Route::post('/webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret'])->name('.webhooks.rotate-secret');
        Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('.webhooks.destroy');
        Route::post('/webhook-deliveries/{delivery}/retry', [WebhookDeliveryController::class, 'retry'])->name('.webhook-deliveries.retry');

        Route::get('/messages', [LogController::class, 'messages'])->name('.messages.index');
        Route::get('/messages/{message}/media', [MessageMediaController::class, 'dashboard'])->name('.messages.media');
        Route::get('/audit-logs', [LogController::class, 'audit'])->name('.audit.index');
        Route::get('/settings', [SettingsController::class, 'index'])->name('.settings.index');
        Route::patch('/settings', [SettingsController::class, 'update'])->name('.settings.update');
        Route::get('/settings/apply', [SettingsController::class, 'showApply'])->name('.settings.apply.show');
        Route::post('/settings/apply', [SettingsController::class, 'apply'])->name('.settings.apply');

        Route::get('/marketplace', [MarketplaceController::class, 'index'])->name('.marketplace.index');
        Route::get('/marketplace/{plugin}', [MarketplaceController::class, 'show'])->name('.marketplace.show');
        Route::post('/marketplace/{plugin}/enable', [MarketplaceController::class, 'enable'])->name('.marketplace.enable');
        Route::post('/marketplace/{plugin}/disable', [MarketplaceController::class, 'disable'])->name('.marketplace.disable');
        Route::patch('/marketplace/{plugin}/settings', [MarketplaceController::class, 'updateSettings'])->name('.marketplace.settings');
        Route::patch('/marketplace/{plugin}/license', [MarketplaceController::class, 'updateLicense'])->name('.marketplace.license');
    });
});

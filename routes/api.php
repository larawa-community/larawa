<?php

use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\CloudConversationController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\SessionDiscoveryController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\WebhookDeliveryController;
use App\Http\Controllers\Api\WhatsappTemplateController;
use App\Http\Controllers\Internal\WorkerEventController;
use App\Http\Controllers\MessageMediaController;
use App\Http\Controllers\MetaWhatsappWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api.key:*', 'throttle:api'])->group(function () {
    Route::get('/sessions', [SessionController::class, 'index'])->middleware('api.key:sessions:read');
    Route::post('/sessions', [SessionController::class, 'store'])->middleware('api.key:sessions:write');
    Route::patch('/sessions/{session}', [SessionController::class, 'update'])->middleware('api.key:sessions:write');
    Route::get('/sessions/{session}', [SessionController::class, 'show'])->middleware('api.key:sessions:read');
    Route::get('/sessions/{session}/chats', [SessionDiscoveryController::class, 'chats'])->middleware('api.key:sessions:read');
    Route::get('/sessions/{session}/contacts', [SessionDiscoveryController::class, 'contacts'])->middleware('api.key:sessions:read');
    Route::get('/sessions/{session}/groups', [SessionDiscoveryController::class, 'groups'])->middleware('api.key:sessions:read');
    Route::post('/sessions/{session}/refresh', [SessionController::class, 'refresh'])->middleware('api.key:sessions:write');
    Route::post('/sessions/{session}/disconnect', [SessionController::class, 'disconnect'])->middleware('api.key:sessions:write');
    Route::post('/sessions/{session}/logout', [SessionController::class, 'logout'])->middleware('api.key:sessions:write');
    Route::delete('/sessions/{session}', [SessionController::class, 'destroy'])->middleware('api.key:sessions:write');

    Route::get('/messages', [MessageController::class, 'index'])->middleware('api.key:messages:read');
    Route::get('/messages/{message}/media', [MessageMediaController::class, 'api'])->middleware('api.key:messages:read');
    Route::post('/sessions/{session}/messages/text', [MessageController::class, 'sendText'])->middleware('api.key:messages:send');
    Route::post('/sessions/{session}/messages/media', [MessageController::class, 'sendMedia'])->middleware('api.key:messages:send');
    Route::post('/sessions/{session}/messages/image', [MessageController::class, 'sendImage'])->middleware('api.key:messages:send');
    Route::post('/sessions/{session}/messages/video', [MessageController::class, 'sendVideo'])->middleware('api.key:messages:send');
    Route::post('/sessions/{session}/messages/document', [MessageController::class, 'sendDocument'])->middleware('api.key:messages:send');
    Route::post('/sessions/{session}/messages/audio', [MessageController::class, 'sendAudio'])->middleware('api.key:messages:send');
    Route::post('/sessions/{session}/messages/reaction', [MessageController::class, 'sendReaction'])->middleware('api.key:messages:send');
    Route::post('/sessions/{session}/messages/template', [MessageController::class, 'sendTemplate'])->middleware('api.key:messages:send');
    Route::post('/sessions/{session}/messages/bulk', [MessageController::class, 'bulk'])->middleware('api.key:messages:send');

    Route::get('/sessions/{session}/conversations', [CloudConversationController::class, 'index'])->middleware('api.key:messages:read');
    Route::get('/sessions/{session}/conversations/{conversation}', [CloudConversationController::class, 'show'])->middleware('api.key:messages:read');
    Route::post('/sessions/{session}/conversations/{conversation}/messages/text', [CloudConversationController::class, 'reply'])->middleware('api.key:messages:send');
    Route::post('/sessions/{session}/conversations/{conversation}/messages/template', [CloudConversationController::class, 'sendTemplate'])->middleware('api.key:messages:send');

    Route::get('/sessions/{session}/templates', [WhatsappTemplateController::class, 'index'])->middleware('api.key:templates:read');
    Route::post('/sessions/{session}/templates/sync', [WhatsappTemplateController::class, 'sync'])->middleware('api.key:templates:write');
    Route::post('/sessions/{session}/templates', [WhatsappTemplateController::class, 'store'])->middleware('api.key:templates:write');
    Route::patch('/sessions/{session}/templates/{template}', [WhatsappTemplateController::class, 'update'])->middleware('api.key:templates:write');

    Route::get('/webhooks', [WebhookController::class, 'index'])->middleware('api.key:webhooks:read');
    Route::post('/webhooks', [WebhookController::class, 'store'])->middleware('api.key:webhooks:write');
    Route::patch('/webhooks/{webhook}', [WebhookController::class, 'update'])->middleware('api.key:webhooks:write');
    Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])->middleware('api.key:webhooks:write');
    Route::post('/webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret'])->middleware('api.key:webhooks:write');
    Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->middleware('api.key:webhooks:write');
    Route::get('/webhook-deliveries', [WebhookDeliveryController::class, 'index'])->middleware('api.key:webhooks:read');
    Route::post('/webhook-deliveries/{delivery}/retry', [WebhookDeliveryController::class, 'retry'])->middleware('api.key:webhooks:write');

    Route::get('/api-keys', [ApiKeyController::class, 'index'])->middleware('api.key:api-keys:read');
    Route::post('/api-keys', [ApiKeyController::class, 'store'])->middleware('api.key:api-keys:write');
    Route::patch('/api-keys/{apiKey}', [ApiKeyController::class, 'update'])->middleware('api.key:api-keys:write');
    Route::post('/api-keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate'])->middleware('api.key:api-keys:write');
    Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->middleware('api.key:api-keys:write');
});

Route::post('/internal/worker/events', WorkerEventController::class)->middleware('internal.worker');

Route::get('/meta/whatsapp/webhook/{session}', [MetaWhatsappWebhookController::class, 'verify']);
Route::post('/meta/whatsapp/webhook/{session}', [MetaWhatsappWebhookController::class, 'receive']);

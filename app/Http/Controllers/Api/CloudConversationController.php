<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappConversation;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\MessageSender;
use App\Services\MessageSendResult;
use App\Services\MetaWhatsappTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CloudConversationController extends Controller
{
    public function index(Request $request, WhatsappSession $session): JsonResponse
    {
        $this->assertSession($request, $session);
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $conversations = $session->conversations()
            ->withCount('messages')
            ->latest('latest_message_at')
            ->paginate($data['per_page'] ?? 50)
            ->withQueryString()
            ->through(fn (WhatsappConversation $conversation) => $this->conversationData($conversation));

        return response()->json(['data' => $conversations]);
    }

    public function show(Request $request, WhatsappSession $session, WhatsappConversation $conversation): JsonResponse
    {
        $this->assertSession($request, $session);
        $this->assertConversation($session, $conversation);
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json([
            'data' => $this->conversationData($conversation),
            'messages' => $conversation->messages()
                ->oldest('created_at')
                ->paginate($data['per_page'] ?? 50)
                ->withQueryString(),
        ]);
    }

    public function reply(
        Request $request,
        WhatsappSession $session,
        WhatsappConversation $conversation,
        MessageSender $sender,
        AuditLogger $audit,
    ): JsonResponse {
        $workspace = $this->assertSession($request, $session);
        $this->assertConversation($session, $conversation);
        $data = $request->validate([
            'text' => ['required', 'string', 'max:4096'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);
        $result = $sender->send($workspace, $session, array_filter([
            'type' => 'text',
            'to' => $conversation->customer_wa_id,
            'text' => $data['text'],
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ], fn ($value) => $value !== null));

        $audit->log(
            $result->failed() ? 'api.cloud_conversation.reply_failed' : 'api.cloud_conversation.replied',
            $workspace,
            apiKey: $request->attributes->get('apiKey'),
            auditable: $result->message,
            metadata: ['conversation_id' => $conversation->id],
            request: $request,
        );

        return $this->messageResponse($result);
    }

    public function sendTemplate(
        Request $request,
        WhatsappSession $session,
        WhatsappConversation $conversation,
        MessageSender $sender,
        AuditLogger $audit,
        MetaWhatsappTemplateService $templates,
    ): JsonResponse {
        $workspace = $this->assertSession($request, $session);
        $this->assertConversation($session, $conversation);
        $data = $request->validate([
            'template_id' => ['required', 'string', 'regex:/^\d+$/'],
            'components' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);
        $template = $templates->find($session, $data['template_id']);
        abort_unless($template->status === 'APPROVED' && $template->is_active, 404);
        $result = $sender->send($workspace, $session, array_filter([
            'type' => 'template',
            'to' => $conversation->customer_wa_id,
            'name' => $template->name,
            'language' => $template->language,
            'components' => $data['components'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ], fn ($value) => $value !== null));

        $audit->log(
            $result->failed() ? 'api.cloud_template.send_failed' : 'api.cloud_template.sent',
            $workspace,
            apiKey: $request->attributes->get('apiKey'),
            auditable: $result->message,
            metadata: ['conversation_id' => $conversation->id, 'meta_template_id' => $template->meta_template_id],
            request: $request,
        );

        return $this->messageResponse($result);
    }

    private function assertSession(Request $request, WhatsappSession $session): Workspace
    {
        $workspace = $this->workspace($request);
        $this->assertSessionAllowed($workspace, $session);
        abort_unless($session->isCloudApi(), 404);

        return $workspace;
    }

    private function assertConversation(WhatsappSession $session, WhatsappConversation $conversation): void
    {
        abort_unless(
            $conversation->whatsapp_session_id === $session->id
            && $conversation->workspace_id === $session->workspace_id,
            404,
        );
    }

    private function conversationData(WhatsappConversation $conversation): array
    {
        return array_merge($conversation->toArray(), [
            'service_window_open' => $conversation->serviceWindowIsOpen(),
        ]);
    }

    private function messageResponse(MessageSendResult $result): JsonResponse
    {
        if ($result->failed()) {
            return response()->json(['message' => $result->error, 'data' => $result->message], $result->status);
        }

        return response()->json(['data' => $result->message], $result->status);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappConversation;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\MessageSender;
use App\Services\MessageSendResult;
use App\Services\MetaWhatsappTemplateMessageBuilder;
use App\Services\MetaWhatsappTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        MetaWhatsappTemplateMessageBuilder $builder,
    ): JsonResponse {
        $workspace = $this->assertSession($request, $session);
        $this->assertConversation($session, $conversation);
        $data = $request->validate([
            'template_id' => ['required', 'string', 'regex:/^\d+$/'],
            'parameters' => ['nullable', 'array'],
            'parameters.*' => ['nullable', 'string', 'max:4096'],
            'components' => ['nullable', 'array'],
            'header_media_type' => ['nullable', 'in:image,video,document'],
            'media_base64' => ['nullable', 'string'],
            'mime_type' => ['nullable', 'required_with:media_base64', 'string', 'max:120'],
            'filename' => ['nullable', 'string', 'max:160'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);
        if (array_key_exists('parameters', $data) && array_key_exists('components', $data)) {
            throw ValidationException::withMessages(['parameters' => 'Use parameters or components, not both.']);
        }
        if (filled($data['header_media_type'] ?? null) && ! filled($data['media_base64'] ?? null)) {
            throw ValidationException::withMessages(['media_base64' => 'media_base64 is required with header_media_type.']);
        }
        $template = $templates->find($session, $data['template_id']);
        abort_unless($template->status === 'APPROVED' && $template->is_active, 404);
        $mediaField = collect($builder->parameterSchema($template))->first(
            fn (array $field) => $field['component'] === 'header' && $field['input'] === 'file'
        );
        if ($mediaField && ! filled($data['media_base64'] ?? null)) {
            throw ValidationException::withMessages(['media_base64' => $mediaField['label'].' is required for this template.']);
        }
        if ($mediaField) {
            $data['header_media_type'] = $mediaField['format'];
            $this->assertValidTemplateMedia($data);
        } elseif (filled($data['media_base64'] ?? null)) {
            throw ValidationException::withMessages(['media_base64' => 'This template does not use a media header.']);
        }
        $components = array_key_exists('components', $data)
            ? $data['components']
            : $builder->components($template, $data['parameters'] ?? []);
        $result = $sender->send($workspace, $session, array_filter([
            'type' => 'template',
            'to' => $conversation->customer_wa_id,
            'name' => $template->name,
            'language' => $template->language,
            'components' => $components,
            'text' => $builder->bodyText($template, $data['parameters'] ?? [], $components),
            'header_media_type' => $data['header_media_type'] ?? null,
            'media_base64' => $data['media_base64'] ?? null,
            'mime_type' => $data['mime_type'] ?? null,
            'filename' => $data['filename'] ?? null,
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

    private function assertValidTemplateMedia(array $data): void
    {
        if (! filled($data['media_base64'] ?? null)) {
            return;
        }
        $normalized = preg_replace('/\s+/', '', $data['media_base64']) ?: '';
        $decoded = base64_decode($normalized, true);
        if ($normalized === '' || strlen($normalized) % 4 !== 0 || ! preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $normalized) || $decoded === false) {
            throw ValidationException::withMessages(['media_base64' => 'media_base64 must be valid base64.']);
        }
        if (strlen($decoded) > (int) config('larawa.media_base64_max_bytes')) {
            throw ValidationException::withMessages(['media_base64' => 'media_base64 exceeds the maximum decoded media size.']);
        }
        $prefix = match ($data['header_media_type']) {
            'image' => 'image/',
            'video' => 'video/',
            'document' => null,
        };
        if ($prefix && ! str_starts_with(strtolower($data['mime_type']), $prefix)) {
            throw ValidationException::withMessages(['mime_type' => "The MIME type does not match the {$data['header_media_type']} header media type."]);
        }
    }
}

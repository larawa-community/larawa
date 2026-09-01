<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WhatsappConversation;
use App\Models\WhatsappSession;
use App\Services\AuditLogger;
use App\Services\MessageSender;
use App\Services\MetaWhatsappAccountService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CloudConversationController extends Controller
{
    public function index(Request $request, WhatsappSession $session): View
    {
        $this->authorizeCloudSession($request, $session, 'cloud-conversations.view');

        $selected = $this->conversationQuery($session)->first();

        return $this->render($request, $session, $selected);
    }

    public function show(Request $request, WhatsappSession $session, WhatsappConversation $conversation): View
    {
        $this->authorizeCloudSession($request, $session, 'cloud-conversations.view');
        $this->assertConversationBelongsToSession($conversation, $session);

        return $this->render($request, $session, $conversation);
    }

    public function settings(
        Request $request,
        WhatsappSession $session,
        MetaWhatsappAccountService $account,
    ): View {
        $this->authorizeCloudSession($request, $session, 'cloud-conversations.view');
        $canManageSessions = $request->user()->can('sessions.manage', $session->workspace);
        $accountRefreshError = null;

        if ($canManageSessions && $session->cloudConfig?->isConfigured()) {
            try {
                $account->refreshDetails($session);
                $session->refresh()->load('cloudConfig', 'workspace');
            } catch (ConnectionException|RequestException $exception) {
                $accountRefreshError = $exception instanceof RequestException
                    ? ($exception->response->json('error.message') ?: $exception->response->json('message') ?: 'Meta rejected the account status request.')
                    : 'Meta is unreachable. The last known account status is shown.';
            }
        }

        return view('dashboard.sessions.cloud', [
            'workspace' => $session->workspace,
            'session' => $session,
            'activeSection' => 'settings',
            'canManageSessions' => $canManageSessions,
            'canManageTemplates' => $request->user()->can('cloud-templates.manage', $session->workspace),
            'accountRefreshError' => $accountRefreshError,
        ]);
    }

    public function snapshot(Request $request, WhatsappSession $session): JsonResponse
    {
        $this->authorizeCloudSession($request, $session, 'cloud-conversations.view');
        $data = $request->validate([
            'selected' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $selected = null;
        if (isset($data['selected'])) {
            $selected = WhatsappConversation::query()->findOrFail($data['selected']);
            $this->assertConversationBelongsToSession($selected, $session);
        }

        $conversations = $this->conversationQuery($session)->paginate(30);

        return response()->json([
            'conversations' => $conversations->getCollection()
                ->map(fn (WhatsappConversation $conversation) => $this->conversationData($session, $conversation))
                ->values(),
            'pagination' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'total' => $conversations->total(),
                'has_more' => $conversations->hasMorePages(),
            ],
            'selected' => $selected ? $this->selectedConversationData($session, $selected) : null,
            'messages' => $selected
                ? $this->latestMessages($selected)->map(fn ($message) => [
                    'id' => $message->id,
                    'direction' => $message->direction,
                    'type' => $message->type,
                    'body' => $message->body ?: ucfirst($message->type).' message',
                    'status' => $message->status,
                    'created_at_label' => $message->created_at?->format('M j, H:i'),
                    'mime_type' => $message->mime_type,
                    'filename' => data_get($message->payload, 'filename'),
                    'is_voice' => (bool) data_get($message->payload, 'meta_webhook.audio.voice', false),
                    'media_url' => $message->media_path
                        ? route('dashboard.messages.media', array_filter([
                            'message' => $message,
                            'preview' => in_array($message->type, ['image', 'sticker', 'video', 'audio'], true) ? 1 : null,
                        ]))
                        : null,
                    'download_url' => $message->media_path ? route('dashboard.messages.media', $message) : null,
                ])->values()
                : [],
        ]);
    }

    public function reply(
        Request $request,
        WhatsappSession $session,
        WhatsappConversation $conversation,
        MessageSender $sender,
        AuditLogger $audit,
    ): RedirectResponse {
        $workspace = $this->authorizeCloudSession($request, $session, 'cloud-conversations.reply');
        $this->assertConversationBelongsToSession($conversation, $session);
        $data = $request->validate([
            'text' => ['required', 'string', 'max:4096'],
        ]);

        if (! $conversation->serviceWindowIsOpen()) {
            throw ValidationException::withMessages([
                'text' => 'The 24-hour customer service window is closed. The customer must message this number again before you can reply.',
            ]);
        }

        $result = $sender->send($workspace, $session, [
            'type' => 'text',
            'to' => $conversation->customer_wa_id,
            'text' => $data['text'],
        ]);

        $audit->log(
            $result->failed() ? 'cloud_conversation.reply_failed' : 'cloud_conversation.replied',
            $workspace,
            $request->user(),
            auditable: $result->message,
            metadata: ['conversation_id' => $conversation->id],
            request: $request,
        );

        if ($result->failed()) {
            return back()->withInput()->with('error', $result->error);
        }

        return back();
    }

    public function replyMedia(
        Request $request,
        WhatsappSession $session,
        WhatsappConversation $conversation,
        MessageSender $sender,
        AuditLogger $audit,
    ): RedirectResponse {
        $workspace = $this->authorizeCloudSession($request, $session, 'cloud-conversations.reply');
        $this->assertConversationBelongsToSession($conversation, $session);
        $maxKilobytes = max(1, (int) ceil(config('larawa.media_base64_max_bytes') / 1024));
        $data = $request->validate([
            'attachment' => [
                'required',
                File::types(['jpg', 'jpeg', 'png', 'pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'])
                    ->max($maxKilobytes),
            ],
            'caption' => ['nullable', 'string', 'max:1024'],
        ]);

        if (! $conversation->serviceWindowIsOpen()) {
            throw ValidationException::withMessages([
                'attachment' => 'The 24-hour customer service window is closed. The customer must message this number again before you can reply.',
            ]);
        }

        $file = $data['attachment'];
        $mimeType = $this->uploadedMediaMimeType($file->getClientOriginalExtension(), $file->getMimeType());
        $type = str_starts_with($mimeType, 'image/') ? 'image' : 'document';

        if ($type === 'image' && $file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'attachment' => 'Images may not be larger than 5 MB.',
            ]);
        }

        $contents = $file->get();
        $result = $sender->send($workspace, $session, array_filter([
            'type' => $type,
            'to' => $conversation->customer_wa_id,
            'media_base64' => base64_encode($contents),
            'mime_type' => $mimeType,
            'filename' => $file->getClientOriginalName(),
            'caption' => $data['caption'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));

        $audit->log(
            $result->failed() ? 'cloud_conversation.media_reply_failed' : 'cloud_conversation.media_replied',
            $workspace,
            $request->user(),
            auditable: $result->message,
            metadata: ['conversation_id' => $conversation->id, 'message_type' => $type],
            request: $request,
        );

        if ($result->failed()) {
            return back()->withInput()->with('error', $result->error);
        }

        return back();
    }

    private function render(Request $request, WhatsappSession $session, ?WhatsappConversation $selected): View
    {
        // The inbox always starts with the newest conversations. Older batches are
        // fetched by the infinite-scroll snapshot endpoint as the user scrolls.
        $conversations = $this->conversationQuery($session)->paginate(30, ['*'], 'page', 1);

        $messages = $selected
            ? $this->latestMessages($selected)
            : collect();

        return view('dashboard.sessions.cloud', [
            'workspace' => $session->workspace,
            'session' => $session,
            'activeSection' => 'conversations',
            'conversations' => $conversations,
            'selectedConversation' => $selected,
            'conversationMessages' => $messages,
            'canManageSessions' => $request->user()->can('sessions.manage', $session->workspace),
            'canManageTemplates' => $request->user()->can('cloud-templates.manage', $session->workspace),
        ]);
    }

    private function uploadedMediaMimeType(string $extension, ?string $detectedMimeType): string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => $detectedMimeType ?: 'application/octet-stream',
        };
    }

    private function authorizeCloudSession(Request $request, WhatsappSession $session, string $ability)
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, $ability, $session->workspace);
        $this->assertSessionAllowed($workspace, $session);
        abort_unless($session->isCloudApi(), 404);

        return $workspace;
    }

    private function assertConversationBelongsToSession(WhatsappConversation $conversation, WhatsappSession $session): void
    {
        abort_unless(
            $conversation->whatsapp_session_id === $session->id
            && $conversation->workspace_id === $session->workspace_id,
            404,
        );
    }

    private function conversationQuery(WhatsappSession $session)
    {
        return $session->conversations()
            ->withCount('messages')
            ->orderByDesc('latest_message_at')
            ->orderByDesc('id');
    }

    private function latestMessages(WhatsappConversation $conversation)
    {
        return $conversation->messages()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->reverse()
            ->values();
    }

    private function conversationData(WhatsappSession $session, WhatsappConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'customer_name' => $conversation->customer_name ?: 'WhatsApp customer',
            'customer_wa_id' => '+'.ltrim($conversation->customer_wa_id, '+'),
            'messages_count' => $conversation->messages_count,
            'latest_message_label' => $conversation->latest_message_at?->format('M j, H:i') ?: 'No activity',
            'service_window_open' => $conversation->serviceWindowIsOpen(),
            'show_url' => route('dashboard.sessions.conversations.show', [$session, $conversation]),
        ];
    }

    private function selectedConversationData(WhatsappSession $session, WhatsappConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'customer_name' => $conversation->customer_name ?: 'WhatsApp customer',
            'customer_wa_id' => '+'.ltrim($conversation->customer_wa_id, '+'),
            'service_window_open' => $conversation->serviceWindowIsOpen(),
            'service_window_expires_label' => $conversation->service_window_expires_at?->format('M j, Y H:i T'),
            'reply_url' => route('dashboard.sessions.conversations.messages.text', [$session, $conversation]),
            'media_reply_url' => route('dashboard.sessions.conversations.messages.media', [$session, $conversation]),
        ];
    }
}

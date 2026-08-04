<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WhatsappConversation;
use App\Models\WhatsappSession;
use App\Services\AuditLogger;
use App\Services\MessageSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function settings(Request $request, WhatsappSession $session): View
    {
        $this->authorizeCloudSession($request, $session, 'cloud-conversations.view');

        return view('dashboard.sessions.cloud', [
            'workspace' => $session->workspace,
            'session' => $session,
            'activeSection' => 'settings',
            'canManageSessions' => $request->user()->can('sessions.manage', $session->workspace),
            'canManageTemplates' => $request->user()->can('cloud-templates.manage', $session->workspace),
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
            ],
            'selected' => $selected ? $this->selectedConversationData($session, $selected) : null,
            'messages' => $selected
                ? $this->latestMessages($selected)->map(fn ($message) => [
                    'id' => $message->id,
                    'direction' => $message->direction,
                    'body' => $message->body ?: ucfirst($message->type).' message',
                    'status' => $message->status,
                    'created_at_label' => $message->created_at?->format('M j, H:i'),
                    'media_url' => $message->media_path ? route('dashboard.messages.media', $message) : null,
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

        return back()->with('status', 'Reply queued for delivery.');
    }

    private function render(Request $request, WhatsappSession $session, ?WhatsappConversation $selected): View
    {
        $conversations = $this->conversationQuery($session)->paginate(30);

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

    private function authorizeCloudSession(Request $request, WhatsappSession $session, string $ability)
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, $ability, $session->workspace);
        abort_unless($session->workspace_id === $workspace->id, 404);
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
        ];
    }
}

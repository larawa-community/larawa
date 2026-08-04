<?php

namespace App\Http\Controllers\Dashboard;

use App\Data\MetaWhatsappTemplate;
use App\Http\Controllers\Controller;
use App\Models\WhatsappConversation;
use App\Models\WhatsappSession;
use App\Services\AuditLogger;
use App\Services\MessageSender;
use App\Services\MetaWhatsappTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CloudConversationController extends Controller
{
    public function __construct(private readonly MetaWhatsappTemplateService $metaTemplates) {}

    public function index(Request $request, WhatsappSession $session): View
    {
        $this->authorizeCloudSession($request, $session, 'cloud-conversations.view');

        $selected = $session->conversations()
            ->latest('latest_message_at')
            ->first();

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
                'text' => 'The 24-hour customer service window is closed. Send an approved template to reopen the conversation.',
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

    public function sendTemplate(
        Request $request,
        WhatsappSession $session,
        WhatsappConversation $conversation,
        MessageSender $sender,
        AuditLogger $audit,
    ): RedirectResponse {
        $workspace = $this->authorizeCloudSession($request, $session, 'cloud-conversations.reply');
        $this->assertConversationBelongsToSession($conversation, $session);
        $data = $request->validate([
            'template_id' => ['required', 'string', 'regex:/^\d+$/'],
            'parameters' => ['nullable', 'array'],
            'parameters.*' => ['nullable', 'string', 'max:4096'],
        ]);
        $template = $this->approvedTemplate($session, $data['template_id']);
        $components = CloudTemplateController::sendComponents($template, $data['parameters'] ?? []);

        $result = $sender->send($workspace, $session, array_filter([
            'type' => 'template',
            'to' => $conversation->customer_wa_id,
            'name' => $template->name,
            'language' => $template->language,
            'components' => $components,
        ], fn ($value) => $value !== []));

        $audit->log(
            $result->failed() ? 'cloud_template.send_failed' : 'cloud_template.sent',
            $workspace,
            $request->user(),
            auditable: $result->message,
            metadata: ['conversation_id' => $conversation->id, 'meta_template_id' => $template->meta_template_id],
            request: $request,
        );

        if ($result->failed()) {
            return back()->withInput()->with('error', $result->error);
        }

        return back()->with('status', 'Template message queued for delivery.');
    }

    private function render(Request $request, WhatsappSession $session, ?WhatsappConversation $selected): View
    {
        $conversations = $session->conversations()
            ->withCount('messages')
            ->latest('latest_message_at')
            ->paginate(30);

        $messages = $selected
            ? $selected->messages()->oldest('created_at')->limit(200)->get()
            : collect();

        try {
            $approvedTemplates = $this->metaTemplates->all($session)
                ->where('status', 'APPROVED')
                ->where('is_active', true)
                ->values();
            $templateLoadError = null;
        } catch (ValidationException $exception) {
            $approvedTemplates = collect();
            $templateLoadError = $exception->errors()['meta'][0] ?? $exception->getMessage();
        }

        return view('dashboard.sessions.cloud', [
            'workspace' => $session->workspace,
            'session' => $session,
            'activeSection' => 'conversations',
            'conversations' => $conversations,
            'selectedConversation' => $selected,
            'conversationMessages' => $messages,
            'approvedTemplates' => $approvedTemplates,
            'templateLoadError' => $templateLoadError,
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

    private function approvedTemplate(WhatsappSession $session, string $templateId): MetaWhatsappTemplate
    {
        $template = $this->metaTemplates->find($session, $templateId);
        abort_unless($template->status === 'APPROVED' && $template->is_active, 404);

        return $template;
    }
}

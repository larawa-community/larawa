<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudConversationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_scope_lists_only_the_cloud_sessions_conversations_and_messages(): void
    {
        [$workspace, $session, $conversation] = $this->cloudConversation();
        $other = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Other Cloud',
            'type' => WhatsappSession::TYPE_CLOUD,
            'status' => 'ready',
        ]);
        $otherConversation = WhatsappConversation::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $other->id,
            'customer_wa_id' => '15559990000',
        ]);
        Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'transport_session_id' => $session->id,
            'conversation_id' => $conversation->id,
            'direction' => 'incoming',
            'type' => 'text',
            'status' => 'received',
            'from' => $conversation->customer_wa_id,
            'body' => 'Hello',
        ]);
        [, $key] = app(ApiKeyService::class)->create($workspace, 'Read', ['messages:read']);

        $this->withToken($key)
            ->getJson("/api/v1/sessions/{$session->uuid}/conversations")
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $conversation->id)
            ->assertJsonPath('data.data.0.service_window_open', true)
            ->assertJsonMissing(['id' => $otherConversation->id]);

        $this->withToken($key)
            ->getJson("/api/v1/sessions/{$session->uuid}/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('messages.data.0.body', 'Hello');
    }

    public function test_free_form_reply_requires_an_open_window_and_send_scope(): void
    {
        [$workspace, $session, $conversation] = $this->cloudConversation();
        [, $readKey] = app(ApiKeyService::class)->create($workspace, 'Read', ['messages:read']);
        [, $sendKey] = app(ApiKeyService::class)->create($workspace, 'Send', ['messages:send']);

        $this->withToken($readKey)
            ->postJson("/api/v1/sessions/{$session->uuid}/conversations/{$conversation->id}/messages/text", ['text' => 'Reply'])
            ->assertForbidden();

        $conversation->update(['service_window_expires_at' => now()->subMinute()]);
        $this->withToken($sendKey)
            ->postJson("/api/v1/sessions/{$session->uuid}/conversations/{$conversation->id}/messages/text", ['text' => 'Too late'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'customer_service_window_closed');

        $conversation->update(['service_window_expires_at' => now()->addHour()]);
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);
        $this->withToken($sendKey)
            ->postJson("/api/v1/sessions/{$session->uuid}/conversations/{$conversation->id}/messages/text", ['text' => 'In time'])
            ->assertAccepted()
            ->assertJsonPath('data.wa_message_id', 'wamid.reply');
    }

    public function test_approved_live_meta_template_can_be_sent_when_the_window_is_closed(): void
    {
        [$workspace, $session, $conversation] = $this->cloudConversation();
        $conversation->update(['service_window_expires_at' => now()->subMinute()]);
        $template = [
            'id' => '983328304742817',
            'name' => 'welcome_customer',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => 'Welcome {{1}}']],
            'status' => 'APPROVED',
        ];
        [, $key] = app(ApiKeyService::class)->create($workspace, 'Send', ['messages:send']);
        Http::fake([
            '*/waba-1/message_templates*' => Http::response(['data' => [$template]]),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.template']]]),
        ]);

        $this->withToken($key)
            ->postJson("/api/v1/sessions/{$session->uuid}/conversations/{$conversation->id}/messages/template", [
                'template_id' => $template['id'],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ada']]]],
            ])
            ->assertAccepted()
            ->assertJsonPath('data.wa_message_id', 'wamid.template')
            ->assertJsonPath('data.body', 'Welcome Ada');
    }

    /** @return array{Workspace, WhatsappSession, WhatsappConversation} */
    private function cloudConversation(): array
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Cloud',
            'type' => WhatsappSession::TYPE_CLOUD,
            'status' => 'ready',
        ]);
        $session->cloudConfig()->create([
            'waba_id' => 'waba-1',
            'phone_number_id' => 'phone-1',
            'access_token' => 'cloud-token',
            'app_secret' => 'app-secret',
        ]);
        $conversation = WhatsappConversation::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'customer_wa_id' => '15551234567',
            'customer_name' => 'Ada',
            'latest_inbound_at' => now(),
            'latest_message_at' => now(),
            'service_window_expires_at' => now()->addHours(24),
        ]);

        return [$workspace, $session->fresh()->load('cloudConfig'), $conversation];
    }
}

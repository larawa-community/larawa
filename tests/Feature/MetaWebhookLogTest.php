<?php

namespace Tests\Feature;

use App\Models\MetaWebhookLog;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\Messaging\CloudApiWhatsappTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaWebhookLogTest extends TestCase
{
    use RefreshDatabase;

    private function deliver(array $payload, string $secret = 'secret')
    {
        config(['larawa.meta.app_secret' => 'secret']);
        $raw = json_encode($payload);

        return $this->call('POST', '/api/meta/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret),
        ], $raw);
    }

    private function payload(string $phone = 'phone-1', string $waba = 'waba-1', string $field = 'messages'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => $waba, 'changes' => [[
                'field' => $field, 'value' => [
                    'metadata' => ['phone_number_id' => $phone],
                    'messages' => [['id' => 'wamid.log-test', 'from' => '15551234567', 'type' => 'text', 'text' => ['body' => '<script>example</script>']]],
                ],
            ]],
        ]]];
    }

    private function createCloudSession(): Workspace
    {
        $workspace = Workspace::create(['name' => 'Logs', 'slug' => 'logs']);
        $session = WhatsappSession::create(['workspace_id' => $workspace->id, 'name' => 'Cloud', 'type' => WhatsappSession::TYPE_CLOUD]);
        $session->cloudConfig()->create(['waba_id' => 'waba-1', 'phone_number_id' => 'phone-1']);

        return $workspace;
    }

    public function test_every_delivery_is_logged_while_processing_stays_deduplicated(): void
    {
        $this->createCloudSession();
        $this->deliver($this->payload())->assertOk();
        $this->deliver($this->payload())->assertOk();
        $this->assertDatabaseCount('meta_webhook_logs', 2);
        $this->assertDatabaseCount('meta_webhook_receipts', 1);
        $this->assertDatabaseCount('messages', 1);
        foreach (MetaWebhookLog::all() as $log) {
            $this->assertSame('processed', $log->receipt->status);
            $this->assertSame('Accepted', $log->routing[0]['reason']);
            $this->assertSame(200, $log->http_status);
        }
    }

    public function test_rejected_and_skipped_events_retain_payload_and_reason(): void
    {
        $this->createCloudSession();
        $this->deliver($this->payload(), 'wrong-secret')->assertUnauthorized();
        $this->assertStringContainsString('Invalid webhook signature', MetaWebhookLog::latest('id')->first()->response);
        $this->deliver($this->payload('unknown'))->assertOk();
        $this->assertStringContainsString('not configured', MetaWebhookLog::latest('id')->first()->routing[0]['reason']);
        $this->deliver($this->payload('phone-1', 'wrong-waba'))->assertOk();
        $this->assertStringContainsString('WABA ID does not match', MetaWebhookLog::latest('id')->first()->routing[0]['reason']);
        $this->deliver($this->payload('phone-1', 'waba-1', 'message_template_status_update'))->assertOk();
        $this->assertStringContainsString('Unsupported event field', MetaWebhookLog::latest('id')->first()->routing[0]['reason']);
        $this->assertDatabaseCount('meta_webhook_logs', 4);
        $this->assertDatabaseCount('meta_webhook_receipts', 0);
        $this->assertStringContainsString('message_template_status_update', MetaWebhookLog::latest('id')->first()->body);
    }

    public function test_invalid_body_and_verification_are_logged_without_verification_secrets(): void
    {
        $this->call('POST', '/api/meta/whatsapp/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{broken')->assertStatus(400);
        $this->assertSame('{broken', MetaWebhookLog::first()->body);
        config(['larawa.meta.verify_token' => 'private-token']);
        $this->get('/api/meta/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=private-token&hub.challenge=private-challenge')->assertOk();
        $log = MetaWebhookLog::latest('id')->first();
        $this->assertStringNotContainsString('private-token', $log->toJson());
        $this->assertStringNotContainsString('private-challenge', $log->toJson());
    }

    public function test_mixed_batch_preserves_all_events_and_links_only_accepted_processing(): void
    {
        $this->createCloudSession();
        $payload = $this->payload();
        $payload['entry'][] = $this->payload('unknown')['entry'][0];
        $this->deliver($payload)->assertOk();
        $log = MetaWebhookLog::first();
        $this->assertCount(2, $log->routing);
        $this->assertCount(2, json_decode($log->body, true)['entry']);
        $this->assertCount(1, $log->receipt->payload['entry']);
    }

    public function test_processing_failure_is_visible_on_the_linked_receipt(): void
    {
        $this->createCloudSession();
        $this->mock(CloudApiWhatsappTransport::class)
            ->shouldReceive('downloadMedia')->once()->andThrow(new \RuntimeException('Media download failed'));
        $payload = $this->payload();
        $payload['entry'][0]['changes'][0]['value']['messages'][0] = [
            'id' => 'media-id', 'from' => '15551234567', 'type' => 'image', 'image' => ['id' => 'asset-id'],
        ];
        $this->deliver($payload)->assertStatus(500);
        $log = MetaWebhookLog::first();
        $this->assertSame(500, $log->http_status);
        $this->assertSame('failed', $log->receipt->status);
        $this->assertSame('Media download failed', $log->receipt->error);
    }

    public function test_oversized_request_has_a_bounded_log_and_remains_rejected(): void
    {
        $this->call('POST', '/api/meta/whatsapp/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat('x', 3 * 1024 * 1024 + 1))->assertStatus(413);
        $log = MetaWebhookLog::first();
        $this->assertTrue($log->truncated);
        $this->assertSame(3 * 1024 * 1024, strlen($log->body));
    }

    public function test_only_site_admins_can_read_logs_and_payload_is_escaped(): void
    {
        $workspace = $this->createCloudSession();
        $this->deliver($this->payload())->assertOk();
        $log = MetaWebhookLog::first();
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user)->get(route('dashboard.meta-webhooks.index'))->assertForbidden();
        $this->get(route('dashboard.meta-webhooks.show', $log))->assertForbidden();
        $workspace->users()->updateExistingPivot($user->id, ['role' => 'site_admin']);
        $this->actingAs($user->fresh())->get(route('dashboard.meta-webhooks.index'))->assertOk()->assertSee('Meta Webhook Logs');
        $this->get(route('dashboard.meta-webhooks.show', $log))->assertOk()
            ->assertSee('&lt;script&gt;example&lt;/script&gt;', false)
            ->assertDontSee('<script>example</script>', false);
    }
}

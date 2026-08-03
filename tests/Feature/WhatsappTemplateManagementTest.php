<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappTemplateManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_sync_follows_pagination_updates_cache_and_marks_missing_templates_inactive(): void
    {
        [$workspace, $session] = $this->cloudSession();
        $missing = $session->cloudConfig->messageTemplates()->create([
            'meta_template_id' => 'old-template',
            'name' => 'old_template',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'components' => [],
            'status' => 'APPROVED',
            'is_active' => true,
        ]);
        $missingWithoutRemoteId = $session->cloudConfig->messageTemplates()->create([
            'name' => 'local_draft',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'components' => [],
            'status' => 'PENDING',
            'is_active' => true,
        ]);
        [, $key] = app(ApiKeyService::class)->create($workspace, 'Templates', ['templates:write']);
        Http::fake([
            'https://graph.facebook.com/v25.0/waba-1/message_templates*' => Http::response([
                'data' => [[
                    'id' => 'meta-1',
                    'name' => 'order_update',
                    'language' => 'en_US',
                    'category' => 'UTILITY',
                    'parameter_format' => 'POSITIONAL',
                    'components' => [['type' => 'BODY', 'text' => 'Order {{1}}']],
                    'status' => 'APPROVED',
                ]],
                'paging' => ['next' => 'https://graph.facebook.com/v25.0/page-two'],
            ]),
            'https://graph.facebook.com/v25.0/page-two' => Http::response([
                'data' => [[
                    'id' => 'meta-2',
                    'name' => 'sale_notice',
                    'language' => 'en_US',
                    'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => 'Sale now']],
                    'status' => 'PENDING',
                ]],
            ]),
        ]);

        $this->withToken($key)
            ->postJson("/api/v1/sessions/{$session->uuid}/templates/sync")
            ->assertOk()
            ->assertJsonCount(4, 'data');

        $this->assertFalse($missing->fresh()->is_active);
        $this->assertFalse($missingWithoutRemoteId->fresh()->is_active);
        $this->assertDatabaseHas('whatsapp_message_templates', ['meta_template_id' => 'meta-1', 'status' => 'APPROVED', 'is_active' => true]);
        $this->assertDatabaseHas('whatsapp_message_templates', ['meta_template_id' => 'meta-2', 'status' => 'PENDING', 'is_active' => true]);
    }

    public function test_api_creates_and_edits_a_guided_template_and_audits_remote_mutations(): void
    {
        [$workspace, $session] = $this->cloudSession();
        [, $key] = app(ApiKeyService::class)->create($workspace, 'Templates', ['templates:write']);
        Http::fake([
            'https://graph.facebook.com/v25.0/waba-1/message_templates' => Http::response(['id' => 'meta-created', 'status' => 'PENDING', 'category' => 'UTILITY']),
            'https://graph.facebook.com/v25.0/meta-created' => Http::response(['success' => true]),
        ]);

        $created = $this->withToken($key)->postJson("/api/v1/sessions/{$session->uuid}/templates", [
            'name' => 'order_confirmation',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'parameter_format' => 'POSITIONAL',
            'header' => ['type' => 'TEXT', 'text' => 'Order update', 'example_text' => 'Order update'],
            'body' => ['text' => 'Hello {{1}}, order {{2}} is ready.', 'example_values' => ['John', 'A-123']],
            'footer' => ['text' => 'Thank you'],
            'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Got it'],
                ['type' => 'URL', 'text' => 'Track order', 'url' => 'https://example.com/orders/{{1}}', 'example' => 'A-123'],
                ['type' => 'PHONE_NUMBER', 'text' => 'Call us', 'phone_number' => '+15551234567'],
            ],
        ])->assertCreated()->assertJsonPath('data.meta_template_id', 'meta-created');

        $templateId = $created->json('data.id');
        $this->withToken($key)->patchJson("/api/v1/sessions/{$session->uuid}/templates/{$templateId}", [
            'category' => 'MARKETING',
        ])->assertOk()->assertJsonPath('data.category', 'MARKETING');

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/waba-1/message_templates')) {
                return false;
            }

            return $request['components'][1]['example']['body_text'] === [['John', 'A-123']]
                && $request['components'][3]['buttons'][1]['example'] === ['A-123'];
        });
        $this->assertSame(1, AuditLog::where('action', 'api.whatsapp_template.created')->count());
        $this->assertSame(1, AuditLog::where('action', 'api.whatsapp_template.updated')->count());
    }

    public function test_media_header_upload_uses_app_id_and_caches_the_returned_handle(): void
    {
        [$workspace, $session] = $this->cloudSession(['app_id' => 'app-1']);
        [, $key] = app(ApiKeyService::class)->create($workspace, 'Templates', ['templates:write']);
        Http::fake([
            'https://graph.facebook.com/v25.0/app-1/uploads' => Http::response(['id' => 'upload:session-1']),
            'https://graph.facebook.com/v25.0/upload:session-1' => Http::response(['h' => 'sample-handle']),
            'https://graph.facebook.com/v25.0/waba-1/message_templates' => Http::response(['id' => 'meta-media', 'status' => 'PENDING']),
        ]);

        $this->withToken($key)->postJson("/api/v1/sessions/{$session->uuid}/templates", [
            'name' => 'document_notice',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'header' => [
                'type' => 'DOCUMENT',
                'sample_media' => [
                    'filename' => 'sample.pdf',
                    'mime_type' => 'application/pdf',
                    'data_base64' => base64_encode('sample PDF'),
                ],
            ],
            'body' => ['text' => 'Your document is ready.'],
        ])->assertCreated();

        $this->assertSame(
            ['sample-handle'],
            WhatsappMessageTemplate::where('meta_template_id', 'meta-media')->firstOrFail()->components[0]['example']['header_handle'],
        );
    }

    public function test_scopes_workspace_ownership_and_meta_errors_are_enforced_without_failing_session(): void
    {
        [$workspace, $session] = $this->cloudSession();
        [$otherWorkspace, $otherSession] = $this->cloudSession([], 'Other');
        [, $readKey] = app(ApiKeyService::class)->create($workspace, 'Read', ['templates:read']);
        [, $writeKey] = app(ApiKeyService::class)->create($workspace, 'Write', ['templates:write']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Missing management permission', 'code' => 200]], 403)]);

        $this->withToken($readKey)->getJson("/api/v1/sessions/{$session->uuid}/templates")->assertOk();
        $this->withToken($readKey)->postJson("/api/v1/sessions/{$session->uuid}/templates/sync")->assertForbidden();
        $this->withToken($writeKey)->getJson("/api/v1/sessions/{$session->uuid}/templates")->assertForbidden();
        $this->withToken($writeKey)->postJson("/api/v1/sessions/{$otherSession->uuid}/templates/sync")->assertNotFound();

        $this->withToken($writeKey)
            ->postJson("/api/v1/sessions/{$session->uuid}/templates/sync")
            ->assertUnprocessable()
            ->assertJsonPath('errors.meta.0', 'Missing management permission (Meta error 200)');

        $this->assertSame('ready', $session->fresh()->status);
        $this->assertNotSame($workspace->id, $otherWorkspace->id);
    }

    /** @return array{Workspace, WhatsappSession} */
    private function cloudSession(array $config = [], string $workspaceName = 'Acme'): array
    {
        $workspace = Workspace::create(['name' => $workspaceName, 'slug' => strtolower($workspaceName).'-'.uniqid()]);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => $workspaceName.' Cloud',
            'type' => WhatsappSession::TYPE_CLOUD,
            'status' => 'ready',
        ]);
        $unique = uniqid();
        $session->cloudConfig()->create(array_merge([
            'waba_id' => 'waba-1',
            'phone_number_id' => 'phone-'.$unique,
            'access_token' => 'cloud-token',
            'app_secret' => 'app-secret',
        ], $config));

        return [$workspace, $session->fresh()->load('cloudConfig')];
    }
}

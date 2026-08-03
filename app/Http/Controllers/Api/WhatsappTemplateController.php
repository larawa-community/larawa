<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use App\Services\AuditLogger;
use App\Services\MetaWhatsappTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WhatsappTemplateController extends Controller
{
    public function index(Request $request, WhatsappSession $session): JsonResponse
    {
        $workspace = $this->workspace($request);
        $this->assertSession($session, $workspace->id);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', Rule::in(['UTILITY', 'MARKETING'])],
            'language' => ['nullable', 'string', 'max:35'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $session->messageTemplates()->latest('updated_at');
        foreach (['status', 'category', 'language'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($filter, $filters[$filter]);
            }
        }
        if (array_key_exists('active', $filters)) {
            $query->where('is_active', $filters['active']);
        }

        return response()->json(['data' => $query->paginate($filters['per_page'] ?? 50)]);
    }

    public function sync(Request $request, WhatsappSession $session, MetaWhatsappTemplateService $templates, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        $this->assertSession($session, $workspace->id);
        $synced = $templates->sync($session);
        $audit->log('api.whatsapp_template.synced', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $session, metadata: ['received' => $synced->count()], request: $request);

        return response()->json([
            'message' => 'Templates synchronized from Meta.',
            'data' => $session->messageTemplates()->latest('updated_at')->get(),
        ]);
    }

    public function store(Request $request, WhatsappSession $session, MetaWhatsappTemplateService $templates, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        $this->assertSession($session, $workspace->id);
        $template = $templates->create($session, $request->validate($templates->rules()));
        $audit->log('api.whatsapp_template.created', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $template, metadata: ['meta_template_id' => $template->meta_template_id], request: $request);

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, WhatsappSession $session, WhatsappMessageTemplate $template, MetaWhatsappTemplateService $templates, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        $this->assertSession($session, $workspace->id);
        $template = $templates->update($session, $template, $request->validate($templates->rules(true)));
        $audit->log('api.whatsapp_template.updated', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $template, metadata: ['meta_template_id' => $template->meta_template_id], request: $request);

        return response()->json(['data' => $template]);
    }

    private function assertSession(WhatsappSession $session, int $workspaceId): void
    {
        abort_unless($session->workspace_id === $workspaceId, 404);
        if (! $session->isCloudApi()) {
            abort(422, 'Message templates are only available for Official Cloud API sessions.');
        }
    }
}

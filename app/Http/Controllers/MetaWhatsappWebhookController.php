<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMetaWhatsappWebhook;
use App\Models\MetaWebhookReceipt;
use App\Models\WhatsappCloudConfig;
use App\Models\WhatsappSession;
use App\Services\MetaAppSettings;
use App\Services\MetaWebhookToken;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MetaWhatsappWebhookController extends Controller
{
    public function verify(Request $request, ?WhatsappSession $session = null): Response
    {
        if ($session) {
            abort_unless($session->isCloudApi() && $session->workspace?->allowsSessionType($session->type), 404);
        }
        try {
            $token = app(MetaWebhookToken::class)->value();
        } catch (\RuntimeException $exception) {
            report($exception);

            return response('Webhook verification is temporarily unavailable.', 503);
        }
        $provided = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        if ($token !== '' && $mode === 'subscribe' && hash_equals($token, $provided)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Invalid webhook verification token.', 403);
    }

    public function receive(Request $request, ?WhatsappSession $session = null): JsonResponse
    {
        if ($session) {
            abort_unless($session->isCloudApi() && $session->workspace?->allowsSessionType($session->type), 404);
        }
        $raw = $request->getContent();
        if (strlen($raw) > 3 * 1024 * 1024) {
            return response()->json(['message' => 'WhatsApp webhook payload exceeds Meta\'s 3 MB limit.'], 413);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload) || ($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response()->json(['message' => 'Invalid WhatsApp webhook payload.'], 400);
        }

        $appSecret = app(MetaAppSettings::class)->current()->app_secret;
        if (! filled($appSecret)) {
            return response()->json(['message' => 'Complete the Meta app settings before receiving webhooks.'], 409);
        }

        $signature = (string) $request->header('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $raw, (string) $appSecret);
        if ($signature === '' || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $accepted = $this->authorizedPayload($payload);
        if ($accepted['entry'] === []) {
            Log::notice('Meta WhatsApp webhook contained no authorized phone numbers for this callback.', [
                'session_uuid' => $session?->uuid,
            ]);

            return response()->json(['ok' => true]);
        }

        try {
            $receipt = MetaWebhookReceipt::create([
                'payload_hash' => hash('sha256', $accepted === $payload ? $raw : $raw.json_encode($accepted)),
                'payload' => $accepted,
                'status' => 'pending',
            ]);
            ProcessMetaWhatsappWebhook::dispatch($receipt);
        } catch (UniqueConstraintViolationException) {
            // Meta retries webhook deliveries; an identical raw payload is already queued or processed.
        }

        return response()->json(['ok' => true]);
    }

    private function authorizedPayload(array $payload): array
    {
        $phoneIds = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $id = data_get($change, 'value.metadata.phone_number_id');
                if (is_string($id) && $id !== '') {
                    $phoneIds[] = $id;
                }
            }
        }

        // Authentication is site-wide; the configured phone number determines the workspace.
        $configs = WhatsappCloudConfig::query()
            ->whereIn('phone_number_id', array_unique($phoneIds))
            ->whereHas('whatsappSession', fn ($query) => $query->where('type', WhatsappSession::TYPE_CLOUD))
            ->with('whatsappSession.workspace')
            ->get()
            ->filter(fn ($config) => $config->whatsappSession?->workspace?->allowsSessionType(WhatsappSession::TYPE_CLOUD))
            ->keyBy('phone_number_id');

        $accepted = $payload;
        $accepted['entry'] = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            $changes = [];
            foreach ($entry['changes'] ?? [] as $change) {
                $phoneId = data_get($change, 'value.metadata.phone_number_id');
                $config = is_string($phoneId) ? $configs->get($phoneId) : null;
                if (! $config || ($change['field'] ?? null) !== 'messages') {
                    continue;
                }
                if (isset($entry['id']) && (string) $entry['id'] !== (string) $config->waba_id) {
                    continue;
                }
                $changes[] = $change;
            }
            if ($changes !== []) {
                $entry['changes'] = $changes;
                $accepted['entry'][] = $entry;
            }
        }

        return $accepted;
    }
}

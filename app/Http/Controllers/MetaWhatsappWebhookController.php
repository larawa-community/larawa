<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMetaWhatsappWebhook;
use App\Models\MetaWebhookReceipt;
use App\Models\WhatsappCloudConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MetaWhatsappWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) config('larawa.meta.webhook_verify_token');
        $provided = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        if ($token !== '' && $mode === 'subscribe' && hash_equals($token, $provided)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Invalid webhook verification token.', 403);
    }

    public function receive(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > 3 * 1024 * 1024) {
            return response()->json(['message' => 'WhatsApp webhook payload exceeds Meta\'s 3 MB limit.'], 413);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload) || ($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response()->json(['message' => 'Invalid WhatsApp webhook payload.'], 400);
        }

        $phoneIds = $this->phoneNumberIds($payload);
        $configs = WhatsappCloudConfig::query()->whereIn('phone_number_id', $phoneIds)->get();
        if ($configs->isEmpty()) {
            Log::notice('Meta WhatsApp webhook referenced no configured phone number.', ['phone_number_ids' => $phoneIds]);

            return response()->json(['ok' => true]);
        }

        $signature = (string) $request->header('X-Hub-Signature-256');
        $valid = $configs->contains(function (WhatsappCloudConfig $config) use ($signature, $raw): bool {
            $expected = 'sha256='.hash_hmac('sha256', $raw, (string) $config->app_secret);

            return $signature !== '' && hash_equals($expected, $signature);
        });
        if (! $valid) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        try {
            $receipt = MetaWebhookReceipt::create([
                'payload_hash' => hash('sha256', $raw),
                'payload' => $payload,
                'status' => 'pending',
            ]);
            ProcessMetaWhatsappWebhook::dispatch($receipt);
        } catch (UniqueConstraintViolationException) {
            // Meta retries webhook deliveries; an identical raw payload is already queued or processed.
        }

        return response()->json(['ok' => true]);
    }

    private function phoneNumberIds(array $payload): array
    {
        $ids = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $id = data_get($change, 'value.metadata.phone_number_id');
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}

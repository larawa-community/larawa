<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSession;
use App\Services\AuditLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class CloudRegistrationTestController extends Controller
{
    public function store(Request $request, WhatsappSession $session, AuditLogger $audit)
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'sessions.manage', $session->workspace);
        $this->assertSessionAllowed($workspace, $session);
        abort_unless($session->isCloudApi() && ! $workspace->suspended_at, 404);
        $redirect = fn () => redirect()->route('dashboard.sessions.cloud-settings', $session);
        $validator = Validator::make($request->all(), [
            'pin' => ['required', 'digits:6'],
            'confirmed' => ['accepted'],
            'phone_number_id' => ['prohibited'],
            'access_token' => ['prohibited'],
            'session_uuid' => ['prohibited'],
        ]);
        if ($validator->fails()) {
            return $redirect()->withErrors($validator, 'registration')->with('registration_errors_'.$session->uuid, $validator->errors()->all());
        }

        $config = $session->cloudConfig;
        $phoneNumberId = $config?->phone_number_id;
        $accessToken = $config?->effectiveAccessToken();
        if (! filled($phoneNumberId) || ! filled($accessToken)) {
            $message = 'Save this session’s phone number ID and configure the provider token in System Settings before registration.';

            return $redirect()->withErrors(['configuration' => $message], 'registration')->with('registration_errors_'.$session->uuid, [$message]);
        }

        $pin = $request->input('pin');
        $secrets = [$accessToken, $pin];
        $url = rtrim(config('larawa.meta.graph_url'), '/').'/'.config('larawa.meta.graph_version').'/'.$phoneNumberId.'/register';
        $body = ['messaging_product' => 'whatsapp', 'pin' => $pin];
        $result = [
            'success' => false,
            'request' => ['method' => 'POST', 'url' => $url, 'headers' => [], 'body' => $this->redact($body, $secrets)],
            'response' => ['status' => null, 'headers' => [], 'body' => null],
            'error' => null,
        ];
        try {
            // Do not retry: a timeout can happen after Meta has registered the phone.
            $response = Http::timeout((int) config('larawa.meta.timeout', 30))->acceptJson()->asJson()
                ->withToken($accessToken)
                ->beforeSending(function ($outgoing) use (&$result, $secrets) {
                    $result['request']['headers'] = $this->redact($outgoing->headers(), $secrets);
                })
                ->post($url, $body);
            $result['success'] = $response->successful() && $response->json('success') === true;
            $rawBody = $response->body();
            $decoded = json_decode($rawBody, true);
            $safeBody = json_last_error() === JSON_ERROR_NONE
                ? json_encode($this->redact($decoded, $secrets), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
                : $this->redact($rawBody, $secrets);
            $result['response'] = [
                'status' => $response->status(),
                'headers' => $this->redact($response->headers(), $secrets),
                'body' => mb_strcut($safeBody, 0, 65536).(strlen($safeBody) > 65536 ? "\n[Response truncated after 64 KiB]" : ''),
            ];
            if (! $result['success']) {
                $result['error'] = $this->redact($response->json('error.message') ?: 'Meta did not confirm registration. See the response below.', $secrets);
            }
        } catch (ConnectionException $exception) {
            $result['error'] = 'Registration outcome is unknown. Check WhatsApp Manager before retrying. '.$this->redact($exception->getMessage(), $secrets);
        }

        $audit->log('cloud_account.registration_test', $workspace, $request->user(), auditable: $session,
            metadata: ['http_status' => $result['response']['status'], 'success' => $result['success']], request: $request);

        return $redirect()->with('registration_result_'.$session->uuid, $result);
    }

    private function redact(mixed $value, array $secrets): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $sensitive = is_string($key) && preg_match('/authorization|cookie|token|secret|password|^pin$/i', $key);
                $value[$key] = $sensitive ? '[redacted]' : $this->redact($item, $secrets);
            }

            return $value;
        }

        return is_string($value) ? str_replace($secrets, '[redacted]', $value) : $value;
    }
}

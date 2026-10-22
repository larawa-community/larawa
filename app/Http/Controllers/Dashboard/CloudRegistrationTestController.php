<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSession;
use App\Services\AuditLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class CloudRegistrationTestController extends Controller
{
    public function index(Request $request)
    {
        Gate::forUser($request->user())->authorize('platform.admin');

        return view('dashboard.settings.meta-registration', [
            'workspace' => $this->workspace($request),
            'sessions' => $this->sessions()->with(['workspace', 'cloudConfig'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit)
    {
        Gate::forUser($request->user())->authorize('platform.admin');
        // Never flash the PIN, including when validation fails.
        $validator = Validator::make($request->only(['session_uuid', 'pin', 'confirmed']), [
            'session_uuid' => ['required', 'uuid'],
            'pin' => ['required', 'digits:6'],
            'confirmed' => ['accepted'],
        ]);
        $redirect = fn () => redirect()->route('dashboard.settings.meta-registration.index');
        if ($validator->fails()) {
            return $redirect()->withErrors($validator)->withInput($request->only('session_uuid'));
        }

        $session = $this->sessions()->with('cloudConfig')->where('uuid', $request->input('session_uuid'))->firstOrFail();
        $config = $session->cloudConfig;
        if (! $config?->isConfigured()) {
            return $redirect()->withErrors(['session_uuid' => 'Save the session WABA ID, phone number ID, and access token first.']);
        }

        $path = '/'.config('larawa.meta.graph_version').'/'.$config->phone_number_id.'/register';
        try {
            // No automatic retries: a timeout may occur after Meta processed registration.
            $response = Http::timeout((int) config('larawa.meta.timeout', 30))->acceptJson()
                ->withToken($config->access_token)
                ->post(rtrim(config('larawa.meta.graph_url'), '/').$path, [
                    'messaging_product' => 'whatsapp',
                    'pin' => $request->input('pin'),
                ]);
        } catch (ConnectionException) {
            return $redirect()->with('error', 'Meta could not be reached or the request timed out. Registration outcome is unknown; check WhatsApp Manager before retrying.');
        }

        $success = $response->successful() && $response->json('success') === true;
        $result = [
            'session' => $session->name,
            'phone_number_id' => $config->phone_number_id,
            'http_status' => $response->status(),
            'success' => $success,
        ];
        // Only expose useful diagnostics, never an arbitrary provider response or credentials.
        foreach (['message', 'type', 'code', 'error_subcode', 'fbtrace_id'] as $field) {
            $value = $response->json('error.'.$field);
            if (is_scalar($value)) {
                $result['error'][$field] = str_replace([$config->access_token, $request->input('pin')], '[redacted]', (string) $value);
            }
        }
        $audit->log('cloud_account.registration_test', $session->workspace, $request->user(), auditable: $session,
            metadata: ['http_status' => $response->status(), 'success' => $success], request: $request);

        return $redirect()->with('registration_result', $result)->withInput($request->only('session_uuid'));
    }

    private function sessions()
    {
        return WhatsappSession::query()->where('type', WhatsappSession::TYPE_CLOUD)
            ->whereHas('workspace', fn ($query) => $query->where('allows_official_cloud_api', true)->whereNull('suspended_at'));
    }
}

<?php

namespace App\Services;

use App\Models\WhatsappCloudConfig;
use App\Models\WhatsappSession;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class MetaWhatsappAccountService
{
    public function refreshDetails(WhatsappSession $session): array
    {
        $details = $this->details($session);

        $this->storeDetails($session, $details);

        return $details;
    }

    public function details(WhatsappSession $session): array
    {
        $config = $this->credentials($session);

        return $this->http($config)->get($this->url($config->phone_number_id), [
            'fields' => 'display_phone_number,verified_name,name_status,new_display_name,new_name_status,is_pin_enabled',
        ])->throw()->json();
    }

    public function setTwoFactorPin(WhatsappSession $session, string $pin): array
    {
        $config = $this->credentials($session);

        return $this->http($config)
            ->post($this->url($config->phone_number_id), ['pin' => $pin])
            ->throw()
            ->json();
    }

    public function requestDisplayName(WhatsappSession $session, string $displayName): array
    {
        $config = $this->credentials($session);

        return $this->http($config)
            ->post($this->url($config->phone_number_id), ['new_display_name' => $displayName])
            ->throw()
            ->json();
    }

    public function applyApprovedDisplayName(WhatsappSession $session, string $pin): array
    {
        $details = $this->details($session);
        if (strtoupper((string) ($details['new_name_status'] ?? '')) !== 'APPROVED') {
            throw ValidationException::withMessages([
                'display_name_pin' => 'Meta has not approved the requested display name yet. Refresh the account status and try again after approval.',
            ]);
        }

        $config = $this->credentials($session);
        $result = $this->http($config)->post($this->url($config->phone_number_id.'/register'), [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ])->throw()->json();

        return ['details' => $details, 'response' => $result];
    }

    public function storeDetails(WhatsappSession $session, array $details): void
    {
        $fields = collect($details)->only([
            'display_phone_number',
            'verified_name',
            'name_status',
            'new_display_name',
            'new_name_status',
            'is_pin_enabled',
        ])->all();
        $fields['refreshed_at'] = now()->toISOString();

        $metadata = $session->metadata ?: [];
        data_set($metadata, 'cloud_api.account', array_merge(data_get($metadata, 'cloud_api.account', []), $fields));
        $session->update(['metadata' => $metadata]);

        if (filled($details['display_phone_number'] ?? null)) {
            $session->update(['phone_number' => $details['display_phone_number']]);
        }
    }

    private function credentials(WhatsappSession $session): WhatsappCloudConfig
    {
        $config = $session->cloudConfig()->firstOrFail();
        if (! $config->isConfigured()) {
            throw ValidationException::withMessages([
                'account' => 'Complete the Meta app settings before managing this WhatsApp account.',
            ]);
        }

        return $config;
    }

    private function http(WhatsappCloudConfig $config): PendingRequest
    {
        return Http::timeout((int) config('larawa.meta.timeout', 30))
            ->acceptJson()
            ->withToken($config->access_token);
    }

    private function url(string $path): string
    {
        return rtrim(config('larawa.meta.graph_url'), '/').'/'.config('larawa.meta.graph_version').'/'.ltrim($path, '/');
    }
}

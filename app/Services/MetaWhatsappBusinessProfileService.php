<?php

namespace App\Services;

use App\Models\WhatsappCloudConfig;
use App\Models\WhatsappSession;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MetaWhatsappBusinessProfileService
{
    public const CATEGORIES = [
        'OTHER' => 'Other', 'AUTO' => 'Automotive', 'BEAUTY' => 'Beauty, spa and salon',
        'APPAREL' => 'Clothing and apparel', 'EDU' => 'Education', 'ENTERTAIN' => 'Entertainment',
        'EVENT_PLAN' => 'Event planning and service', 'FINANCE' => 'Finance and banking',
        'GROCERY' => 'Food and grocery', 'GOVT' => 'Public service', 'HOTEL' => 'Hotel and lodging',
        'HEALTH' => 'Medical and health', 'NONPROFIT' => 'Non-profit', 'PROF_SERVICES' => 'Professional services',
        'RETAIL' => 'Shopping and retail', 'TRAVEL' => 'Travel and transportation', 'RESTAURANT' => 'Restaurant',
    ];

    public function refresh(WhatsappSession $session): array
    {
        $config = $this->credentials($session);
        $profile = $this->http($config)->get($this->url($config->phone_number_id.'/whatsapp_business_profile'), [
            'fields' => 'about,address,description,email,profile_picture_url,websites,vertical',
        ])->throw()->json('data.0');

        if (! is_array($profile)) {
            throw new RuntimeException('Meta did not return a business profile. Try refreshing again.');
        }

        $profile = array_merge(array_fill_keys(['about', 'address', 'description', 'email', 'profile_picture_url', 'vertical'], ''), [
            'websites' => [],
        ], array_intersect_key($profile, array_flip(['about', 'address', 'description', 'email', 'profile_picture_url', 'websites', 'vertical'])));
        $profile['phone_number_id'] = $config->phone_number_id;
        $profile['refreshed_at'] = now()->toISOString();
        $metadata = $session->metadata ?: [];
        data_set($metadata, 'cloud_api.business_profile', $profile);
        $session->update(['metadata' => $metadata]);

        return $profile;
    }

    public function update(WhatsappSession $session, array $fields, ?UploadedFile $picture = null): void
    {
        $config = $this->credentials($session);
        $fields = array_intersect_key($fields, array_flip(['about', 'address', 'description', 'email', 'websites', 'vertical']));
        if ($picture) {
            $fields['profile_picture_handle'] = $this->uploadPicture($config, $picture);
        }

        $response = $this->http($config)->post($this->url($config->phone_number_id.'/whatsapp_business_profile'), [
            'messaging_product' => 'whatsapp',
            ...$fields,
        ])->throw();
        if ($response->json('success') !== true) {
            throw new RuntimeException('Meta did not confirm the business profile update. Refresh the profile before trying again.');
        }

        // Cache only confirmed changes; upload handles must never be stored as photo URLs.
        $metadata = $session->metadata ?: [];
        $cached = data_get($metadata, 'cloud_api.business_profile', []);
        if (($cached['phone_number_id'] ?? null) !== $config->phone_number_id) {
            $cached = [];
        }
        unset($fields['profile_picture_handle']);
        if ($picture) {
            $fields['profile_picture_url'] = '';
        }
        data_set($metadata, 'cloud_api.business_profile', array_merge($cached, $fields, [
            'phone_number_id' => $config->phone_number_id,
            'updated_at' => now()->toISOString(),
        ]));
        $session->update(['metadata' => $metadata]);
    }

    private function uploadPicture(WhatsappCloudConfig $config, UploadedFile $picture): string
    {
        $upload = $this->http($config)->post($this->url('app/uploads').'?'.http_build_query([
            'file_length' => $picture->getSize(),
            'file_type' => $picture->getMimeType(),
            'file_name' => $picture->getClientOriginalName(),
        ]))->throw()->json('id');
        if (! is_string($upload) || ! str_starts_with($upload, 'upload:')) {
            throw new RuntimeException('Meta did not create a photo upload session. Please choose the photo again and retry.');
        }

        $handle = $this->http($config)
            ->withToken($config->effectiveAccessToken(), 'OAuth')
            ->withHeaders(['file_offset' => '0'])
            ->withBody($picture->get(), $picture->getMimeType())
            ->post($this->url($upload))->throw()->json('h');
        if (! is_string($handle) || $handle === '') {
            throw new RuntimeException('Meta did not return a photo upload handle. Please choose the photo again and retry.');
        }

        return $handle;
    }

    private function credentials(WhatsappSession $session): WhatsappCloudConfig
    {
        $config = $session->cloudConfig;
        if (! $config?->isConfigured()) {
            throw ValidationException::withMessages(['business_profile' => 'Complete the Meta Cloud configuration before managing the business profile.']);
        }

        return $config;
    }

    private function http(WhatsappCloudConfig $config): PendingRequest
    {
        return Http::timeout((int) config('larawa.meta.timeout', 30))->acceptJson()->withToken($config->effectiveAccessToken());
    }

    private function url(string $path): string
    {
        return rtrim(config('larawa.meta.graph_url'), '/').'/'.config('larawa.meta.graph_version').'/'.ltrim($path, '/');
    }
}

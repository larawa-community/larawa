<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class OutboundUrlGuard
{
    public function assertAllowed(?string $url, string $field, string $allowPrivateConfigKey, string $label): void
    {
        $result = $this->inspect($url, $allowPrivateConfigKey, $label);

        if ($result['allowed']) {
            return;
        }

        $this->reject($field, $result['message']);
    }

    public function inspect(?string $url, string $allowPrivateConfigKey, string $label): array
    {
        if (blank($url)) {
            return [
                'allowed' => true,
                'message' => null,
            ];
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true) || blank($host)) {
            return $this->result("{$label} must be an HTTP or HTTPS URL.");
        }

        if (config($allowPrivateConfigKey)) {
            return [
                'allowed' => true,
                'message' => null,
            ];
        }

        if ($this->isLocalHostname($host)) {
            return $this->result("{$label} cannot point to localhost or private network addresses.");
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->resolveHost($host);

        if ($addresses === []) {
            return $this->result("{$label} host must resolve to a public address.");
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                return $this->result("{$label} cannot point to localhost or private network addresses.");
            }
        }

        return [
            'allowed' => true,
            'message' => null,
        ];
    }

    private function isLocalHostname(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        return $host === 'localhost' || str_ends_with($host, '.localhost');
    }

    private function resolveHost(string $host): array
    {
        $records = dns_get_record($host, DNS_A + DNS_AAAA);

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records ?: []
        )));
    }

    private function isPublicIp(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (bool) filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return (bool) filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return false;
    }

    private function result(string $message): array
    {
        return [
            'allowed' => false,
            'message' => $message,
        ];
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => $message,
        ]);
    }
}

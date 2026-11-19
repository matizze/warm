<?php

namespace App\Support\Media;

/**
 * Resolves a hostname to every address it points to. Kept as an injectable
 * class so tests can pin the DNS answer and exercise the SSRF guard without
 * touching the network.
 */
class HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $host = trim($host, '[]');

        if ($host === '') {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $addresses = array_merge($addresses, $ipv4);
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}

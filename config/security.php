<?php

$trustedProxies = array_values(array_filter(array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', '')))));

foreach ($trustedProxies as $proxy) {
    $parts = explode('/', $proxy);
    $ip = $parts[0];
    $bits = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : 128;
    if (count($parts) > 2 || ! filter_var($ip, FILTER_VALIDATE_IP)
        || (isset($parts[1]) && (! ctype_digit($parts[1]) || (int) $parts[1] < 1 || (int) $parts[1] > $bits))) {
        throw new InvalidArgumentException('TRUSTED_PROXIES must contain explicit IP addresses or non-global CIDR ranges.');
    }
}

return [
    'trusted_proxies' => $trustedProxies,
];

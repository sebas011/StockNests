<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

class TrustConfiguredProxies extends TrustProxies
{
    // The reverse proxy must preserve Host; never accept a forwarded host name.
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        // Do not fall back to framework cloud/host heuristics that trust '*'.
        $this->setTrustedProxyIpAddressesToSpecificIps($request, config('security.trusted_proxies', []));
    }
}

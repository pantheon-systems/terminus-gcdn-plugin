<?php

namespace Pantheon\TerminusGCDN\Tests;

use Pantheon\TerminusGCDN\CloudflareIps;
use PHPUnit\Framework\TestCase;

final class CloudflareIpsTest extends TestCase
{
    public function testCloudflareProxyAddresses(): void
    {
        // frankensteingym.com behind its own Cloudflare zone (2026-11-03).
        foreach (['104.21.39.242', '172.67.172.41', '2606:4700:3032::6815:27f2', '2606:4700:3033::ac43:ac29'] as $ip) {
            $this->assertTrue(CloudflareIps::contains($ip), $ip);
        }
    }

    public function testOtherAddresses(): void
    {
        // Pantheon's GCDN and Fastly addresses, a hostname, garbage.
        foreach (['185.178.196.1', '2a0a:6c80::1', '23.185.0.3', '2620:12a:8001::3', 'fe.cfp1c.edge.pantheon.io', ''] as $value) {
            $this->assertFalse(CloudflareIps::contains($value), $value);
        }
    }

    public function testAllNeedsEveryValue(): void
    {
        $this->assertTrue(CloudflareIps::all(['104.21.39.242', '172.67.172.41']));
        $this->assertFalse(CloudflareIps::all(['104.21.39.242', '185.178.196.1']));
        $this->assertFalse(CloudflareIps::all([]));
    }
}

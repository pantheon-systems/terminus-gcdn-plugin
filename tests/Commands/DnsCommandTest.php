<?php

namespace Pantheon\TerminusGCDN\Tests\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusGCDN\Commands\DnsCommand;
use Pantheon\TerminusGCDN\Tests\Support\CommandHarness;
use Pantheon\TerminusGCDN\Tests\Support\DomainFixtures as F;

/**
 * gcdn:dns, pinned to the behavior at 0.4.0. It's read-only: no test may
 * record a write.
 */
final class DnsCommandTest extends CommandHarness
{
    private function dns(object ...$domains)
    {
        $cmd = new DnsCommand();
        $this->wire($cmd);
        $this->domainsList(...$domains);
        $result = $cmd->dns('my-site.live');
        $this->assertSame([], $this->writes(), 'gcdn:dns is read-only');
        return $result;
    }

    /**
     * Output for one domain: from its header line to the next domain's.
     */
    private function section(string $out, string $domain): string
    {
        $start = strpos($out, $domain . "\n------");
        $this->assertNotFalse($start, "no section for {$domain}");
        $next = strpos($out, "\n------", $start + strlen($domain) + 7);
        if ($next === false) {
            return substr($out, $start);
        }
        return substr($out, $start, strrpos(substr($out, 0, $next), "\n") - $start);
    }

    public function testEveryStateKeysPresentAndAbsent(): void
    {
        $names = [F::PENDING, F::PENDING_CERT, F::ACTIVE, F::ACTIVE_BEFORE_CUTOVER, F::READY, F::CANDIDATE, F::ZONE_HOLD, F::CDN_BOTH, F::FASTLY, F::PLATFORM, F::NO_CHALLENGES, F::CDN_BLANK, F::LEGACY_ACME];
        foreach ([true, false] as $withKeys) {
            $this->calls = [];
            $this->script = [];
            $domains = array_map(fn($name) => $this->domain($name, $withKeys), $names);
            $this->assertNull($this->dns(...$domains));
            $out = $this->plain();

            $this->assertStringContainsString('=== my-site site on live environment hostname dns records ===', $out);
            $this->assertStringContainsString('Cloudflare ownership: verified', $this->section($out, 'active.example.com'));
            $this->assertStringContainsString('Cloudflare ownership: verified', $this->section($out, 'prevalidated.example.com'));
            foreach (['pending.example.com', 'cert.example.com', 'ready.example.com', 'candidate.example.com', 'held.example.com', 'both.example.com'] as $host) {
                $this->assertStringContainsString('Cloudflare ownership: not verified', $this->section($out, $host), $host);
            }
            $this->assertStringNotContainsString('Cloudflare ownership', $this->section($out, 'fastly.example.com'));
            $this->assertStringNotContainsString('Cloudflare ownership', $this->section($out, 'blank.example.com'));
            $this->assertStringContainsString('Value: legacy-cert-1', $this->section($out, 'legacy.example.com'));
        }
    }

    public function testCautionWhenCnameAndAddressRecordsAllMatch(): void
    {
        $domain = $this->domain(F::ACTIVE);
        $domain->dns_status_details->dns_records = [
            (object) ['type' => 'CNAME', 'target_value' => 'fe.cfp1c.edge.pantheon.io', 'detected_value' => 'fe.cfp1c.edge.pantheon.io', 'status' => 'okay'],
            (object) ['type' => 'A', 'target_value' => '104.18.1.1', 'detected_value' => '104.18.1.1', 'status' => 'okay'],
            (object) ['type' => 'AAAA', 'target_value' => '2606:4700::6812:101', 'detected_value' => '2606:4700::6812:101', 'status' => 'okay'],
        ];
        $this->dns($domain);
        $this->assertStringContainsString('Caution: Both CNAME and A/AAAA records are configured.', $this->plain());
    }

    public function testMatchingRecordStillActionRequiredSaysPropagating(): void
    {
        $domain = $this->domain(F::PENDING);
        $domain->dns_status_details->dns_records[1]->detected_value = '104.18.1.1';
        $this->dns($domain);
        $this->assertStringContainsString('A: 104.18.1.1 (current: 104.18.1.1) [propagating verification]', $this->plain());
    }

    public function testNoDomains(): void
    {
        $cmd = new DnsCommand();
        $this->wire($cmd);
        $this->domainsList();
        $this->assertNull($cmd->dns('my-site.live'));
        $this->assertStringContainsString('No domains found on my-site.live', $this->plain());
    }

    public function testGetErrorThrows(): void
    {
        $cmd = new DnsCommand();
        $this->wire($cmd);
        $this->on('GET', self::DOMAINS_PATH, $this->apiResult(500, null));
        $this->expectException(TerminusException::class);
        $this->expectExceptionMessage('Failed to fetch domains for my-site.live.');
        $cmd->dns('my-site.live');
    }

    public function testMissingEnvironmentSuffixFailsBeforeAnyRequest(): void
    {
        $cmd = new DnsCommand();
        $this->wire($cmd);
        try {
            $cmd->dns('my-site');
            $this->fail('expected exception');
        } catch (TerminusException $e) {
            $this->assertStringContainsString('<site_name>.<environment>', $e->getMessage());
        }
        $this->assertSame([], $this->calls);
    }

    public function testUnknownEnvironmentFailsBeforeAnyRequest(): void
    {
        $cmd = new DnsCommand();
        $this->wire($cmd);
        $this->expectException(TerminusException::class);
        $cmd->dns('my-site.nope');
    }

    /** P3: ownership done but certificate pending reads as "not verified"; cert_errors are never shown. */
    public function testP3CertPendingShowsNotVerifiedWithoutCertErrors(): void
    {
        $this->dns($this->domain(F::PENDING_CERT));
        $out = $this->plain();
        $this->assertStringContainsString('Cloudflare ownership: not verified', $out);
        $this->assertStringNotContainsString('could not validate', $out);
    }

    /** P5: shows the rotating cert TXT; never the DCV CNAME. */
    public function testP5ShowsCertTxtNotDcv(): void
    {
        $this->dns($this->domain(F::PENDING));
        $out = $this->plain();
        $this->assertStringContainsString('TXT Record — Certificate Validation:', $out);
        $this->assertStringNotContainsString('dcv.cloudflare.com', $out);
    }

    /** P7: platform hostnames get challenge records the customer can't add. */
    public function testP7PlatformHostnameShowsChallenges(): void
    {
        $this->dns($this->domain(F::PLATFORM));
        $this->assertStringContainsString('_cf-custom-hostname.live-my-site.pantheonsite.io', $this->plain());
    }

    /** P13: cdn "" is treated as Fastly, so no Cloudflare status at all. */
    public function testP13BlankCdnShowsNoCloudflareStatus(): void
    {
        $this->dns($this->domain(F::CDN_BLANK));
        $this->assertStringNotContainsString('Cloudflare ownership', $this->plain());
    }
}

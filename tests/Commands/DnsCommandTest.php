<?php

namespace Pantheon\TerminusGCDN\Tests\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusGCDN\Commands\DnsCommand;
use Pantheon\TerminusGCDN\Tests\Support\CommandHarness;
use Pantheon\TerminusGCDN\Tests\Support\DomainFixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * gcdn:dns: one test per state, with the EDRT-9618 keys present and absent.
 * It's read-only: no test may record a write.
 */
final class DnsCommandTest extends CommandHarness
{
    private function dns(object ...$domains)
    {
        $this->calls = [];
        $this->script = [];
        $cmd = new DnsCommand();
        $this->wire($cmd);
        $this->domainsList(...$domains);
        $result = $cmd->dns('my-site.live');
        $this->assertSame([], $this->writes(), 'gcdn:dns is read-only');
        return $result;
    }

    /**
     * fixture, keys present?, headline, ready (exit 0 on its own)?
     */
    public static function states(): array
    {
        return [
            'pending' => [F::PENDING, true, 'Cloudflare: ownership and certificate pending', false],
            'pending, keys absent' => [F::PENDING, false, 'Cloudflare: ownership and certificate pending', false],
            'ownership done, cert pending' => [F::PENDING_CERT, true, 'Cloudflare: ownership verified, certificate pending', false],
            'active' => [F::ACTIVE, true, 'Cloudflare: ownership and certificate active', true],
            'active, keys absent' => [F::ACTIVE, false, 'Cloudflare: ownership and certificate active', true],
            'ready' => [F::READY, true, 'Cloudflare: DNS records ready (confirmed by Pantheon)', true],
            'ready, keys absent (before #318)' => [F::READY, false, 'Cloudflare: ownership and certificate pending', false],
            'candidate' => [F::CANDIDATE, true, 'Cloudflare: ownership not confirmed yet (served through Cloudflare today)', false],
            'zone hold' => [F::ZONE_HOLD, true, 'held zone. Contact Pantheon support.', false],
            'cdn both' => [F::CDN_BOTH, true, 'Cloudflare: ownership and certificate pending', false],
            'platform' => [F::PLATFORM, true, 'Managed by Pantheon: no DNS records or verification needed.', true],
            'fastly' => [F::FASTLY, true, 'Not on GCDN (Cloudflare).', true],
            'cdn blank' => [F::CDN_BLANK, true, 'Status unavailable', false],
            'challenges missing' => [F::NO_CHALLENGES, true, 'Cloudflare status not available yet. Try again shortly.', false],
        ];
    }

    #[DataProvider('states')]
    public function testEveryState(string $fixture, bool $withKeys, string $headline, bool $ready): void
    {
        $domain = $this->domain($fixture, $withKeys);
        $this->assertSame($ready ? 0 : 1, $this->dns($domain));
        $out = $this->plain();
        $this->assertStringContainsString($headline, $out);
        $this->assertStringNotContainsString('cert-token-', $out, 'P5: never the cert TXT');
        if (!$ready) {
            $this->assertStringContainsString("Not ready yet: {$domain->id}.", $out);
        }
    }

    public function testAllDomainsAreListedAndAnyNotReadyExitsOne(): void
    {
        $this->assertSame(1, $this->dns($this->domain(F::ACTIVE), $this->domain(F::PENDING), $this->domain(F::FASTLY)));
        $out = $this->plain();
        $this->assertStringContainsString('=== my-site site on live environment hostname dns records ===', $out);
        $this->assertStringContainsString("active.example.com\n------", $out);
        $this->assertStringContainsString("fastly.example.com\n------", $out);
        $this->assertStringContainsString('Not ready yet: pending.example.com.', $out);
        $this->assertStringContainsString('terminus gcdn:verify my-site.live <domain>', $out);
    }

    public function testReadyPointsToVerifyForCutover(): void
    {
        $this->dns($this->domain(F::READY));
        $out = $this->plain();
        $this->assertStringContainsString('Name:  _cf-custom-hostname.ready.example.com', $out);
        $this->assertStringContainsString('Value: ready.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', $out);
        $this->assertStringContainsString('terminus gcdn:verify my-site.live ready.example.com', $out);
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
        $this->assertStringContainsString('Caution: both the CNAME and the A/AAAA records are set.', $this->plain());
    }

    public function testMatchingRecordStillActionRequiredSaysPropagating(): void
    {
        $domain = $this->domain(F::PENDING);
        $domain->dns_status_details->dns_records[1]->detected_value = '104.18.1.1';
        $this->dns($domain);
        $this->assertStringContainsString('A: 104.18.1.1 (current: 104.18.1.1) [propagating]', $this->plain());
    }

    public function testNoDomains(): void
    {
        $this->assertSame(0, $this->dns());
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

    /** P3: ownership done, certificate pending shows cert_errors, not the TXT. */
    public function testP3CertPendingShowsCertErrors(): void
    {
        $this->dns($this->domain(F::PENDING_CERT));
        $out = $this->plain();
        $this->assertStringContainsString('Certificate: The certificate authority could not validate', $out);
        $this->assertStringNotContainsString('_cf-custom-hostname', $out);
    }

    /** P5: the DCV CNAME, never the cert TXT. */
    public function testP5ShowsDcvNotCertTxt(): void
    {
        $this->dns($this->domain(F::PENDING));
        $out = $this->plain();
        $this->assertStringContainsString('CNAME record — certificate validation (DCV delegation)', $out);
        $this->assertStringNotContainsString('cert-token-', $out);
    }

    /** P7: platform domains show no challenge records. */
    public function testP7PlatformShowsNoChallenges(): void
    {
        $this->dns($this->domain(F::PLATFORM));
        $out = $this->plain();
        $this->assertStringNotContainsString('_cf-custom-hostname', $out);
        $this->assertStringNotContainsString('_acme-challenge', $out);
    }

    /** P13: cdn "" is "status unavailable". */
    public function testP13BlankCdnIsUnavailable(): void
    {
        $this->assertSame(1, $this->dns($this->domain(F::CDN_BLANK)));
        $this->assertStringNotContainsString('Not on GCDN', $this->plain());
    }
}

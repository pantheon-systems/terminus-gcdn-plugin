<?php

namespace Pantheon\TerminusGCDN\Tests\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusGCDN\Commands\O2oCommand;
use Pantheon\TerminusGCDN\Tests\Support\CommandHarness;
use Pantheon\TerminusGCDN\Tests\Support\DomainFixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * gcdn:o2o: one test per state, with the EDRT-9618 keys present and absent.
 * It's read-only: no test may record a write.
 */
final class O2oCommandTest extends CommandHarness
{
    private function o2o(?string $name, object ...$domains)
    {
        $this->calls = [];
        $this->script = [];
        $cmd = new O2oCommand();
        $this->wire($cmd);
        $this->domainsList(...$domains);
        $result = $cmd->o2o('my-site.live', $name);
        $this->assertSame([], $this->writes(), 'gcdn:o2o is read-only');
        return $result;
    }

    /**
     * fixture, keys present?, headline, exit
     */
    public static function states(): array
    {
        return [
            'pending' => [F::PENDING, true, 'Cloudflare: ownership and certificate pending', 1],
            'pending, keys absent' => [F::PENDING, false, 'Cloudflare: ownership and certificate pending', 1],
            'active' => [F::ACTIVE, true, 'Cloudflare: ownership and certificate active', 0],
            'ready' => [F::READY, true, 'Cloudflare: DNS records ready (confirmed by Pantheon)', 0],
            'ready, keys absent (before #318)' => [F::READY, false, 'Cloudflare: ownership and certificate pending', 1],
            'ready, moved' => [F::READY_MOVED, true, 'Cloudflare paused its checks on this domain (status: moved)', 0],
            'blocked' => [F::BLOCKED, true, 'Cloudflare: action required (status: blocked)', 1],
            'deleted' => [F::DELETED, true, 'Cloudflare marked this domain deleted (status: deleted)', 0],
            'candidate' => [F::CANDIDATE, true, 'Cloudflare: ownership not confirmed yet', 1],
            'zone hold' => [F::ZONE_HOLD, true, 'Cloudflare reports a zone hold on this domain.', 1],
            'zone hold, blocked' => [F::ZONE_HOLD_BLOCKED, true, 'Cloudflare: action required (zone hold, status: blocked)', 1],
            'cdn both' => [F::CDN_BOTH, true, 'Cloudflare: ownership and certificate pending', 1],
            'cdn blank' => [F::CDN_BLANK, true, 'Status unavailable', 1],
            'challenges missing' => [F::NO_CHALLENGES, true, 'Status not available yet', 1],
        ];
    }

    #[DataProvider('states')]
    public function testEveryState(string $fixture, bool $withKeys, string $headline, int $exit): void
    {
        $domain = $this->domain($fixture, $withKeys);
        $this->assertSame($exit, $this->o2o($domain->id, $domain));
        $out = $this->plain();
        $this->assertStringContainsString($headline, $out);
        $this->assertStringNotContainsString('cert-token-', $out);
        $this->assertStringNotContainsString('DcvZones', $out);
    }

    public function testPendingShowsTxtDcvAndGatedTraffic(): void
    {
        $this->o2o(null, $this->domain(F::PENDING));
        $out = $this->plain();
        $this->assertStringContainsString('pending.example.com  (zone: cfp1c)', $out);
        $domain = $this->domain(F::PENDING);
        $this->assertRow($out, 'TXT', '_cf-custom-hostname.pending.example.com', $domain->challenges->ownership_txt->val, 'Add this record.');
        $this->assertRow($out, 'CNAME', '_acme-challenge.pending.example.com', 'pending.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', 'Add this record.');
        $this->assertStringContainsString('Can\'t use a CNAME with this name? Run gcdn:verify with --cert-txt for a TXT record instead.', $out);
        $this->assertStringContainsString('3. Switch traffic to Pantheon: not yet: after steps 1 and 2', $out);
        $this->assertRow($out, 'CNAME', 'pending.example.com', 'fe.cfp1c.edge.pantheon.io', 'Not yet: after the steps above.');
        // Cloudflare-to-Cloudflare: the proxied CNAME alone, no A/AAAA option.
        $this->assertRow($out, '(Proxied)');
        $this->assertStringNotContainsString('Option 2', $out);
        $this->assertStringNotContainsString('│ A ', $out);
    }

    public function testActiveKeepsDcvAndTraffic(): void
    {
        $this->o2o(null, $this->domain(F::ACTIVE));
        $out = $this->plain();
        $this->assertStringContainsString('If you use another proxy in front of Pantheon (Cloudflare or otherwise), keep this record in place: without it, your certificate can fail to renew.', $out);
        $this->assertStringContainsString('If you don\'t, we still recommend keeping it: it\'s extra insurance that nothing can interfere with certificate renewals.', $out);
        $this->assertStringContainsString('3. Switch traffic to Pantheon: done', $out);
        $this->assertRow($out, 'CNAME', 'active.example.com', 'fe.cfp1c.edge.pantheon.io', 'None: points to Pantheon.');
        $this->assertStringNotContainsString('_cf-custom-hostname', $out);
        $this->assertStringNotContainsString('--cert-txt', $out, 'not once the certificate is active');
    }

    /** P8: once ready, the traffic step is open, with the cutover options. */
    public function testP8ReadyOpensTheTrafficStep(): void
    {
        $this->o2o(null, $this->domain(F::READY));
        $out = $this->plain();
        $this->assertStringContainsString('3. Switch traffic to Pantheon: not done: switch whenever you\'re ready', $out);
        $this->assertRow($out, 'CNAME', 'ready.example.com', 'fe.cfp1c.edge.pantheon.io', 'Add it, Proxied.');
        $this->assertStringContainsString('See "Issues and Notes" in step 1.', $out);
        $this->assertRow($out, 'Issue: Ownership');
        $this->assertStringContainsString('The hostname is using Cloudflare', $out);
        $this->assertRow($out, 'What can be done');
        $this->assertStringNotContainsString('Potential issues detected', $out);
        $this->assertStringNotContainsString('cf-dns-verify', $out);
        $this->assertStringContainsString('│ After you switch DNS, wait about 5 minutes for it to propagate', $out);
        $this->assertRow($out, 'terminus gcdn:verify my-site.live ready.example.com');
        $this->assertStringNotContainsString('after ownership is verified', $out);
    }

    public function testOnlyGcdnCustomDomainsAreListed(): void
    {
        $this->assertSame(1, $this->o2o(null, $this->domain(F::PENDING), $this->domain(F::FASTLY), $this->domain(F::PLATFORM), $this->domain(F::CDN_BOTH), $this->domain(F::ACTIVE)));
        $out = $this->plain();
        $this->assertStringContainsString('pending.example.com', $out);
        $this->assertStringContainsString('both.example.com', $out);
        $this->assertStringContainsString('active.example.com', $out);
        $this->assertStringNotContainsString('fastly.example.com', $out);
        $this->assertStringNotContainsString('pantheonsite.io', $out);
    }

    public function testAllActiveExitsZero(): void
    {
        $this->assertSame(0, $this->o2o(null, $this->domain(F::ACTIVE), $this->domain(F::READY)));
    }

    public function testNoEligibleDomains(): void
    {
        $this->assertSame(0, $this->o2o(null, $this->domain(F::FASTLY)));
        $this->assertStringContainsString('No GCDN custom domains found on my-site.live', $this->plain());
    }

    public function testGetErrorThrows(): void
    {
        $cmd = new O2oCommand();
        $this->wire($cmd);
        $this->on('GET', self::DOMAINS_PATH, $this->apiResult(503, null));
        $this->expectException(TerminusException::class);
        $cmd->o2o('my-site.live');
    }

    public function testApiDcvWinsOverTheTable(): void
    {
        $domain = $this->domain(F::PENDING);
        $domain->challenges->dcv_delegation->val = 'pending.example.com.fedcba9876543210.dcv.cloudflare.com';
        $this->o2o(null, $domain);
        $this->assertStringContainsString(' pending.example.com.fedcba9876543210.dcv.cloudflare.com. ', $this->plain());
    }

    /** P9: an unknown zone gets customer-safe text. */
    public function testP9UnknownZoneSaysContactSupport(): void
    {
        $this->o2o(null, $this->domain(F::UNKNOWN_ZONE));
        $out = $this->plain();
        $this->assertStringContainsString('Contact Pantheon support.', $out);
        $this->assertStringNotContainsString('DcvZones', $out);
        $this->assertStringNotContainsString('plugin', $out);
    }

    /** P10: a domain with no zone data never borrows another domain's zone. */
    public function testP10DomainWithoutZoneDoesNotBorrow(): void
    {
        $this->o2o(null, $this->domain(F::PENDING), $this->domain(F::NO_ZONE));
        $out = $this->plain();
        $this->assertStringContainsString("nozone.example.com\n------", $out);
        $this->assertStringNotContainsString('nozone.example.com.dc5ecddca9c0e249', $out);
        $this->assertRow($out, 'Issue: Traffic record', 'Pantheon couldn\'t determine the traffic target for this domain.');
    }

    /** P11: no invalid wildcard DCV target. */
    public function testP11WildcardHasNoInventedDcvTarget(): void
    {
        $this->o2o(null, $this->domain(F::WILDCARD));
        $out = $this->plain();
        $this->assertStringNotContainsString('*.wild.example.com.dc5ecddca9c0e249', $out);
        $this->assertStringContainsString('Contact Pantheon support.', $out);
    }

    /** P12: missing challenges don't point to gcdn:verify. */
    public function testP12MissingChallengesDontPointToVerify(): void
    {
        $this->o2o(null, $this->domain(F::NO_CHALLENGES));
        $this->assertStringNotContainsString('to generate it', $this->plain());
    }

    /** P14: a Fastly or platform domain isn't "not found", and the lookup ignores case. */
    public function testP14FastlyPlatformAndCase(): void
    {
        $this->assertSame(0, $this->o2o('fastly.example.com', $this->domain(F::FASTLY)));
        $this->assertStringContainsString('isn\'t on GCDN (Cloudflare)', $this->plain());

        $this->assertSame(0, $this->o2o('live-my-site.pantheonsite.io', $this->domain(F::PLATFORM)));
        $this->assertStringContainsString('Managed by Pantheon', $this->plain());

        $this->o2o('PENDING.example.com', $this->domain(F::PENDING));
        $this->assertStringContainsString('pending.example.com  (zone: cfp1c)', $this->plain());

        $this->assertSame(1, $this->o2o('nope.example.com', $this->domain(F::PENDING)));
        $this->assertStringContainsString('Domain not found on my-site.live. Add it first:', $this->plain());
    }
}

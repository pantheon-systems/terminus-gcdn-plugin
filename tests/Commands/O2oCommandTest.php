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
            'pending' => [F::PENDING, true, 'Ownership: not confirmed yet.', 1],
            'pending, keys absent' => [F::PENDING, false, 'Ownership: not confirmed yet.', 1],
            'active' => [F::ACTIVE, true, 'Done: ownership and certificate active. No TXT record needed.', 0],
            'ready' => [F::READY, true, 'Ownership: confirmed by Pantheon. Next: switch DNS.', 0],
            'ready, keys absent (before #318)' => [F::READY, false, 'Ownership: not confirmed yet.', 1],
            'candidate' => [F::CANDIDATE, true, 'Ownership: not confirmed yet.', 1],
            'zone hold' => [F::ZONE_HOLD, true, 'held zone. Contact Pantheon support.', 1],
            'cdn both' => [F::CDN_BOTH, true, 'Ownership: not confirmed yet.', 1],
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
        $this->assertStringContainsString('Name:  _cf-custom-hostname.pending.example.com', $out);
        $this->assertStringContainsString('Value: pending.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', $out);
        $this->assertStringContainsString('CNAME record — traffic (add it after Pantheon confirms ownership', $out);
        $this->assertStringContainsString('Value: fe.cfp1c.edge.pantheon.io.', $out);
    }

    public function testActiveKeepsDcvAndTraffic(): void
    {
        $this->o2o(null, $this->domain(F::ACTIVE));
        $out = $this->plain();
        $this->assertStringContainsString('Keep this record permanently', $out);
        $this->assertStringContainsString('CNAME record — traffic (in place)', $out);
        $this->assertStringNotContainsString('_cf-custom-hostname', $out);
    }

    /** P8: once ready, the traffic step is open, with the cutover options. */
    public function testP8ReadyOpensTheTrafficStep(): void
    {
        $this->o2o(null, $this->domain(F::READY));
        $out = $this->plain();
        $this->assertStringContainsString('CNAME record — traffic (switch to it when you\'re ready)', $out);
        $this->assertStringContainsString('Turning proxying off for this', $out);
        $this->assertStringContainsString('Name:  cf-dns-verify.ready.example.com', $out);
        $this->assertStringContainsString('terminus gcdn:verify my-site.live ready.example.com', $out);
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
        $this->assertStringContainsString('Value: pending.example.com.fedcba9876543210.dcv.cloudflare.com.', $this->plain());
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
        $this->assertStringContainsString('Traffic record: couldn\'t determine the target.', $out);
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

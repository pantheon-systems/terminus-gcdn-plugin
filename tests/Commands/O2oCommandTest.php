<?php

namespace Pantheon\TerminusGCDN\Tests\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusGCDN\Commands\O2oCommand;
use Pantheon\TerminusGCDN\Tests\Support\CommandHarness;
use Pantheon\TerminusGCDN\Tests\Support\DomainFixtures as F;

/**
 * gcdn:o2o, pinned to the behavior at 0.4.0. It's read-only: no test may
 * record a write.
 */
final class O2oCommandTest extends CommandHarness
{
    private function o2o(?string $name, object ...$domains)
    {
        $cmd = new O2oCommand();
        $this->wire($cmd);
        $this->domainsList(...$domains);
        $result = $cmd->o2o('my-site.live', $name);
        $this->assertSame([], $this->writes(), 'gcdn:o2o is read-only');
        return $result;
    }

    public function testPendingShowsTxtDcvAndTraffic(): void
    {
        foreach ([true, false] as $withKeys) {
            $this->assertNull($this->o2o(null, $this->domain(F::PENDING, $withKeys)));
            $out = $this->plain();
            $this->assertStringContainsString('pending.example.com  (zone: cfp1c)', $out);
            $this->assertStringContainsString('Name:  _cf-custom-hostname.pending.example.com', $out);
            $this->assertStringContainsString('Value: pending.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', $out);
            $this->assertStringContainsString('Value: fe.cfp1c.edge.pantheon.io.', $out);
        }
    }

    public function testActiveSaysAlreadyVerified(): void
    {
        $this->o2o(null, $this->domain(F::ACTIVE));
        $this->assertStringContainsString('Hostname ownership: already verified', $this->plain());
    }

    public function testReadyIsTreatedAsPendingToday(): void
    {
        $this->o2o(null, $this->domain(F::READY));
        $out = $this->plain();
        $this->assertStringContainsString('TXT — hostname ownership verification', $out);
        $this->assertStringNotContainsString('already verified', $out);
    }

    public function testOnlyCustomCloudflareDomainsAreListed(): void
    {
        $this->o2o(null, $this->domain(F::PENDING), $this->domain(F::FASTLY), $this->domain(F::PLATFORM), $this->domain(F::CDN_BOTH));
        $out = $this->plain();
        $this->assertStringContainsString('pending.example.com', $out);
        $this->assertStringContainsString('both.example.com', $out);
        $this->assertStringNotContainsString('fastly.example.com', $out);
        $this->assertStringNotContainsString('pantheonsite.io', $out);
    }

    public function testDomainArgumentIsCaseInsensitive(): void
    {
        $this->o2o('PENDING.example.com', $this->domain(F::PENDING));
        $this->assertStringContainsString('pending.example.com  (zone: cfp1c)', $this->plain());
    }

    public function testNoEligibleDomains(): void
    {
        $this->o2o(null, $this->domain(F::FASTLY));
        $this->assertStringContainsString('No Cloudflare custom domains found on my-site.live', $this->plain());
    }

    public function testGetErrorThrows(): void
    {
        $cmd = new O2oCommand();
        $this->wire($cmd);
        $this->on('GET', self::DOMAINS_PATH, $this->apiResult(503, null));
        $this->expectException(TerminusException::class);
        $cmd->o2o('my-site.live');
    }

    /** P8: the traffic step says to wait for verification, which a Cloudflare-routed hostname can't reach before cutover. */
    public function testP8TrafficStepWaitsForVerification(): void
    {
        $this->o2o(null, $this->domain(F::READY));
        $this->assertStringContainsString('add LAST, after ownership is verified', $this->plain());
    }

    /** P9: an unknown zone prints developer text. */
    public function testP9UnknownZonePrintsDeveloperText(): void
    {
        $this->o2o(null, $this->domain(F::UNKNOWN_ZONE));
        $this->assertStringContainsString('Update DcvZones in the terminus-gcdn-plugin', $this->plain());
    }

    /** P10: a domain with no zone data borrows another domain's zone. */
    public function testP10DomainWithoutZoneBorrowsAnotherDomainsZone(): void
    {
        $this->o2o(null, $this->domain(F::PENDING), $this->domain(F::NO_ZONE));
        $this->assertStringContainsString('nozone.example.com  (zone: cfp1c)', $this->plain());
    }

    /** P11: a wildcard builds an invalid DCV target. */
    public function testP11WildcardBuildsInvalidDcvTarget(): void
    {
        $this->o2o(null, $this->domain(F::WILDCARD));
        $this->assertStringContainsString('Value: *.wild.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', $this->plain());
    }

    /** P12: missing challenges point to gcdn:verify, which generates nothing for Cloudflare. */
    public function testP12MissingChallengesPointToVerify(): void
    {
        $this->o2o(null, $this->domain(F::NO_CHALLENGES));
        $this->assertStringContainsString('run "terminus gcdn:verify" for this domain to generate it', $this->plain());
    }

    /** P14: a Fastly domain is "not found ... Add it first". */
    public function testP14FastlyDomainSaysAddItFirst(): void
    {
        $this->assertNull($this->o2o('fastly.example.com', $this->domain(F::FASTLY)));
        $this->assertStringContainsString('Add it first:', $this->plain());
    }
}

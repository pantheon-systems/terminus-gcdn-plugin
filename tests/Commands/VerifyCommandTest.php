<?php

namespace Pantheon\TerminusGCDN\Tests\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusGCDN\Commands\VerifyCommand;
use Pantheon\TerminusGCDN\Tests\Support\CommandHarness;
use Pantheon\TerminusGCDN\Tests\Support\DomainFixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * gcdn:verify, pinned to the behavior at 0.4.0. Tests named after a bug ID
 * (see the EDRT-9618 follow-up task, §4) document that bug as it is today.
 */
final class VerifyCommandTest extends CommandHarness
{
    private const VERIFY_PATH = '#^sites/[^/]+/environments/live/hostnames/[^/]+/verify-ownership$#';

    private function verify(object $domain, ?string $name = null, array $options = ['method' => 'dns'], $postResult = null)
    {
        $cmd = new VerifyCommand();
        $this->wire($cmd);
        $this->on('POST', self::VERIFY_PATH, $postResult ?? $this->apiResult(200, (object) ['message' => 'success', 'verified' => false]));
        $this->domainsList($domain);
        return $cmd->verify('my-site.live', $name ?? $domain->id, $options);
    }

    public static function cloudflareCustomStates(): array
    {
        return [
            'pending' => [F::PENDING, 'not verified'],
            'pending cert' => [F::PENDING_CERT, 'not verified'],
            'active' => [F::ACTIVE, 'verified'],
            'active before cutover' => [F::ACTIVE_BEFORE_CUTOVER, 'verified'],
            'ready' => [F::READY, 'not verified'],
            'candidate' => [F::CANDIDATE, 'not verified'],
            'zone hold' => [F::ZONE_HOLD, 'not verified'],
            'cdn both' => [F::CDN_BOTH, 'not verified'],
            'unknown zone' => [F::UNKNOWN_ZONE, 'not verified'],
        ];
    }

    #[DataProvider('cloudflareCustomStates')]
    public function testPostsThenReadsAndReportsVerified(string $fixture, string $expected): void
    {
        foreach ([true, false] as $withKeys) {
            $this->calls = [];
            $this->script = [];
            $domain = $this->domain($fixture, $withKeys);
            $result = $this->verify($domain);
            $out = $this->plain();

            $this->assertNull($result, 'exit 0');
            $this->assertSame([
                'SITES.GET my-site',
                'SITES.GET my-site',
                'POST sites/' . self::SITE_ID . '/environments/live/hostnames/' . rawurlencode($domain->id) . '/verify-ownership',
                'GET sites/' . self::SITE_ID . '/environments/live/domains?hydrate[]=as_list&hydrate[]=recommendations',
            ], $this->callLog());
            $this->assertStringContainsString("Cloudflare ownership: {$expected}\n", $out);
        }
    }

    public function testActiveWithDnsCutOverSaysConfigured(): void
    {
        $this->verify($this->domain(F::ACTIVE));
        $out = $this->plain();
        $this->assertStringContainsString('DNS: configured correctly', $out);
        $this->assertStringContainsString('CNAME: fe.cfp1c.edge.pantheon.io (current: fe.cfp1c.edge.pantheon.io) [okay]', $out);
    }

    public function testActiveBeforeCutoverSaysNotConfigured(): void
    {
        $this->verify($this->domain(F::ACTIVE_BEFORE_CUTOVER));
        $out = $this->plain();
        $this->assertStringContainsString('DNS: not configured', $out);
        $this->assertStringContainsString('A: 104.18.1.1 (current: 151.101.2.133) [action required]', $out);
    }

    public function testPendingShowsOwnershipTxtErrorsAndO2oHint(): void
    {
        $this->verify($this->domain(F::PENDING));
        $out = $this->plain();
        $this->assertStringContainsString('Name:  _cf-custom-hostname.pending.example.com', $out);
        $this->assertStringContainsString('Warning: None of the A or AAAA records', $out);
        $this->assertStringContainsString('Run "terminus gcdn:o2o my-site.live pending.example.com"', $out);
        $this->assertStringContainsString('Add the TXT records above to your DNS provider, then re-run this command.', $out);
    }

    public function testReadyHostnameIsReportedNotVerifiedToday(): void
    {
        // display_dns_records is ignored at 0.4.0.
        $this->verify($this->domain(F::READY));
        $this->assertStringContainsString('Cloudflare ownership: not verified', $this->plain());
    }

    public function testLegacyAcmeChallengesAreShownWhenChallengesMissing(): void
    {
        $this->verify($this->domain(F::LEGACY_ACME));
        $out = $this->plain();
        $this->assertStringContainsString('Name:  _cf-custom-hostname.legacy.example.com', $out);
        $this->assertStringContainsString('Value: legacy-cert-1', $out);
    }

    public function testMissingChallengesSaysNotVerifiedWithNoRecords(): void
    {
        $result = $this->verify($this->domain(F::NO_CHALLENGES));
        $out = $this->plain();
        $this->assertNull($result);
        $this->assertStringContainsString('Cloudflare ownership: not verified', $out);
        $this->assertStringNotContainsString('TXT Record', $out);
    }

    public function testInvalidMethodThrowsBeforeAnyRequest(): void
    {
        $cmd = new VerifyCommand();
        $this->wire($cmd);
        try {
            $cmd->verify('my-site.live', 'pending.example.com', ['method' => 'smtp']);
            $this->fail('expected exception');
        } catch (TerminusException $e) {
            $this->assertStringContainsString('Invalid method', $e->getMessage());
        }
        $this->assertSame([], $this->writes());
    }

    public function testDomainsGetErrorPointsToDomainDns(): void
    {
        $cmd = new VerifyCommand();
        $this->wire($cmd);
        $this->on('POST', self::VERIFY_PATH, $this->apiResult(200, (object) ['verified' => false]));
        $this->on('GET', self::DOMAINS_PATH, $this->apiResult(500, null));
        $this->assertNull($cmd->verify('my-site.live', 'pending.example.com', ['method' => 'dns']));
        $this->assertStringContainsString('Could not fetch DNS status.', $this->plain());
    }

    /** P1: the POST runs before the lookup, so a Fastly hostname gets a site-certificates verify. */
    public function testP1FastlyHostnameIsPostedBeforeTheCdnCheck(): void
    {
        $this->verify($this->domain(F::FASTLY));
        $this->assertCount(1, $this->writes());
        $this->assertStringContainsString('This domain is not on GCDN (Cloudflare).', $this->plain());
    }

    /** P1: a platform hostname is POSTed too, and titan answers 403. */
    public function testP1PlatformHostnameIsPostedAndWarns(): void
    {
        $this->verify($this->domain(F::PLATFORM), null, ['method' => 'dns'], $this->apiResult(403, (object) ['message' => 'Cannot request HTTPS certificates']));
        $this->assertCount(1, $this->writes());
        $this->assertStringContainsString('Could not trigger verification', $this->logger->text());
    }

    /** P2: --method only changes challenge_type, which titan ignores for Cloudflare. Output still shows TXT. */
    public function testP2MethodHttpOnlyChangesTheIgnoredChallengeType(): void
    {
        $this->verify($this->domain(F::PENDING), null, ['method' => 'http']);
        $this->assertSame(['challenge_type' => 'http-01'], $this->writes()[0][2]['form_params']);
        $this->assertStringContainsString('TXT Record — Domain Ownership', $this->plain());
    }

    /** P3: ownership active but certificate pending reads as "not verified", and cert_errors are never shown. */
    public function testP3OwnershipDoneCertPendingAsksForTheTxtAgain(): void
    {
        $this->verify($this->domain(F::PENDING_CERT));
        $out = $this->plain();
        $this->assertStringContainsString('Cloudflare ownership: not verified', $out);
        $this->assertStringContainsString('TXT Record — Domain Ownership', $out);
        $this->assertStringNotContainsString('could not validate', $out);
    }

    /** P4: a POST that exhausts retries throws, so the status is never printed. */
    public function testP4PostFailureHidesTheStatus(): void
    {
        $cmd = new VerifyCommand();
        $this->wire($cmd);
        $this->on('POST', self::VERIFY_PATH, function () {
            throw new TerminusException('HTTP request has failed with error "Maximum retry attempts reached".');
        });
        $this->domainsList($this->domain(F::PENDING));
        try {
            $cmd->verify('my-site.live', 'pending.example.com', ['method' => 'dns']);
            $this->fail('expected exception');
        } catch (TerminusException $e) {
            $this->assertStringContainsString('Maximum retry attempts', $e->getMessage());
        }
        $this->assertStringNotContainsString('Cloudflare ownership', $this->plain());
    }

    /** P5: the rotating cert TXT is shown as the validation record; the DCV CNAME isn't. */
    public function testP5ShowsRawCertTxtInsteadOfDcvCname(): void
    {
        $this->verify($this->domain(F::PENDING));
        $out = $this->plain();
        $this->assertStringContainsString('TXT Record — Certificate Validation:', $out);
        $this->assertStringContainsString('Value: cert-token-', $out);
        $this->assertStringNotContainsString('dcv.cloudflare.com', $out);
    }

    /** P13: cdn "" (a domains-status failure) is reported as "not on GCDN". */
    public function testP13BlankCdnIsReportedAsNotOnGcdn(): void
    {
        $this->verify($this->domain(F::CDN_BLANK));
        $this->assertStringContainsString('This domain is not on GCDN (Cloudflare).', $this->plain());
    }

    /** P14: the domain match is case-sensitive. */
    public function testP14MixedCaseDomainIsNotFound(): void
    {
        $result = $this->verify($this->domain(F::PENDING), 'Pending.Example.com');
        $this->assertNull($result);
        $this->assertStringContainsString('Domain not found. Add it first:', $this->plain());
    }

    /** P16: "not verified" and "not found" exit 0. */
    public function testP16NotVerifiedExitsZero(): void
    {
        $this->assertNull($this->verify($this->domain(F::PENDING)));
        $this->assertStringContainsString('not verified', $this->plain());
    }

    /** P17: an empty ownership value prints a blank "Value:" line. */
    public function testP17EmptyOwnershipValuePrintsBlankLine(): void
    {
        $domain = $this->domain(F::PENDING);
        $domain->challenges->ownership_txt->val = '';
        $this->verify($domain);
        $this->assertMatchesRegularExpression('/Value: \n/', $this->plain());
    }
}

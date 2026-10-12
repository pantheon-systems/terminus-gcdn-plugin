<?php

namespace Pantheon\TerminusGCDN\Tests\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusGCDN\Tests\Support\CommandHarness;
use Pantheon\TerminusGCDN\Tests\Support\DomainFixtures as F;
use Pantheon\TerminusGCDN\Tests\Support\TestVerifyCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * gcdn:verify: one test per state, with the EDRT-9618 keys present and
 * absent, plus the recheck (Force Recheck PATCH), its cooldown and the
 * write-safety rules. Tests named after a bug ID are its regression test.
 */
final class VerifyCommandTest extends CommandHarness
{
    private const DOMAINS_GET = 'GET sites/' . self::SITE_ID . '/environments/live/domains?hydrate[]=as_list&hydrate[]=recommendations';
    private const PATCH_PATH = '#^sites/[^/]+/environments/live/hostnames/[^/]+$#';
    private const VERIFY_PATH = '#^sites/[^/]+/environments/live/hostnames/[^/]+/verify-ownership$#';

    private TestVerifyCommand $cmd;
    private ?string $cacheDir = null;

    protected function tearDown(): void
    {
        if ($this->cacheDir !== null) {
            array_map('unlink', glob($this->cacheDir . '/*') ?: []);
            @rmdir($this->cacheDir);
        }
    }

    private function useCacheDir(): array
    {
        $this->cacheDir = sys_get_temp_dir() . '/gcdn-verify-test-' . bin2hex(random_bytes(4));
        return ['cache_dir' => $this->cacheDir];
    }

    /**
     * Wires a fresh command with the given domains, a successful PATCH and
     * POST, and returns its exit code for $name.
     */
    private function verify(string $name, array $domains, array $options = [], array $config = [], ?callable $configure = null)
    {
        $this->calls = [];
        $this->script = [];
        $this->cmd = new TestVerifyCommand();
        $this->wire($this->cmd, $config);
        if ($configure !== null) {
            $configure($this->cmd);
        }
        $this->domainsList(...$domains);
        $this->on('PATCH', self::PATCH_PATH, $this->apiResult(200, 'Hostname updated successfully.'));
        $this->on('POST', self::VERIFY_PATH, $this->apiResult(200, (object) ['message' => 'success', 'verified' => false]));
        return $this->cmd->verify('my-site.live', $name, $options + ['method' => null, 'no-recheck' => false]);
    }

    private function verifyFixture(string $fixture, bool $withKeys = true, array $options = []): int
    {
        $domain = $this->domain($fixture, $withKeys);
        return $this->verify($domain->id, [$domain], $options);
    }

    private function hostPath(string $host, string $suffix = ''): string
    {
        return 'sites/' . self::SITE_ID . '/environments/live/hostnames/' . rawurlencode($host) . $suffix;
    }

    private function recheckAndPost(string $host): array
    {
        return ['SITES.GET my-site', 'SITES.GET my-site', self::DOMAINS_GET, 'PATCH ' . $this->hostPath($host), 'POST ' . $this->hostPath($host, '/verify-ownership')];
    }

    private function postOnly(string $host): array
    {
        return ['SITES.GET my-site', 'SITES.GET my-site', self::DOMAINS_GET, 'POST ' . $this->hostPath($host, '/verify-ownership')];
    }

    private function readOnly(): array
    {
        return ['SITES.GET my-site', 'SITES.GET my-site', self::DOMAINS_GET];
    }

    /**
     * fixture, keys present?, headline, exit code, writes: recheck (PATCH + POST) | post | none
     */
    public static function states(): array
    {
        return [
            'pending' => [F::PENDING, true, 'Cloudflare: ownership and certificate pending', 1, 'recheck'],
            'pending, keys absent' => [F::PENDING, false, 'Cloudflare: ownership and certificate pending', 1, 'recheck'],
            'ownership done, cert pending' => [F::PENDING_CERT, true, 'Cloudflare: ownership verified, certificate pending', 1, 'recheck'],
            'ownership done, cert pending, keys absent' => [F::PENDING_CERT, false, 'Cloudflare: ownership and certificate pending', 1, 'recheck'],
            'active' => [F::ACTIVE, true, 'Cloudflare: ownership and certificate active', 0, 'post'],
            'active, keys absent' => [F::ACTIVE, false, 'Cloudflare: ownership and certificate active', 0, 'post'],
            'active before cutover' => [F::ACTIVE_BEFORE_CUTOVER, true, 'Cloudflare: ownership and certificate active', 0, 'post'],
            'ready' => [F::READY, true, 'Cloudflare: DNS records ready (confirmed by Pantheon)', 0, 'recheck'],
            'ready, keys absent (before #318)' => [F::READY, false, 'Cloudflare: ownership and certificate pending', 1, 'recheck'],
            'candidate' => [F::CANDIDATE, true, 'Cloudflare: ownership not confirmed yet', 1, 'recheck'],
            'candidate, keys absent' => [F::CANDIDATE, false, 'Cloudflare: ownership and certificate pending', 1, 'recheck'],
            'zone hold' => [F::ZONE_HOLD, true, 'Cloudflare: ownership and certificate pending', 1, 'recheck'],
            'cdn both' => [F::CDN_BOTH, true, 'Cloudflare: ownership and certificate pending', 1, 'recheck'],
            'unknown zone' => [F::UNKNOWN_ZONE, true, 'Cloudflare: ownership and certificate pending', 1, 'recheck'],
            'platform' => [F::PLATFORM, true, 'Managed by Pantheon: platform domains need no DNS records or verification.', 0, 'none'],
            'fastly' => [F::FASTLY, true, 'This domain is not on GCDN (Cloudflare). Its DNS records:', 0, 'none'],
            'cdn blank' => [F::CDN_BLANK, true, 'Status unavailable: Pantheon couldn\'t read this domain\'s CDN status. Try again shortly.', 1, 'none'],
            'challenges missing' => [F::NO_CHALLENGES, true, 'Status not available yet: Pantheon hasn\'t received this domain\'s Cloudflare status. Try again shortly.', 1, 'none'],
            'legacy challenges only' => [F::LEGACY_ACME, true, 'Status not available yet', 1, 'none'],
        ];
    }

    #[DataProvider('states')]
    public function testEveryState(string $fixture, bool $withKeys, string $headline, int $exit, string $writes): void
    {
        $domain = $this->domain($fixture, $withKeys);
        $this->assertSame($exit, $this->verify($domain->id, [$domain]));
        $out = $this->plain();

        $this->assertStringContainsString($headline, $out);
        $expected = ['recheck' => $this->recheckAndPost($domain->id), 'post' => $this->postOnly($domain->id), 'none' => $this->readOnly()][$writes];
        $this->assertSame($expected, $this->callLog());
        if ($writes === 'recheck') {
            $this->assertEquals((object) [], $this->writes()[0][2]['form_params'], 'Force Recheck sends {}');
        }
        // P5: never the rotating cert TXT.
        $this->assertStringNotContainsString('cert-token-', $out);
        $this->assertStringNotContainsString('legacy-cert-1', $out);
    }

    public function testActiveShowsTrafficAndKeepsDcv(): void
    {
        $this->verifyFixture(F::ACTIVE);
        $out = $this->plain();
        $this->assertStringContainsString('DNS: pointed to Pantheon', $out);
        $this->assertStringContainsString('CNAME: fe.cfp1c.edge.pantheon.io (current: fe.cfp1c.edge.pantheon.io) [okay]', $out);
        $this->assertStringContainsString('Value: active.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', $out);
        $this->assertStringContainsString('Keep this record permanently', $out);
        $this->assertStringNotContainsString('_cf-custom-hostname', $out);
    }

    public function testActiveBeforeCutoverSaysNotPointedYet(): void
    {
        $this->verifyFixture(F::ACTIVE_BEFORE_CUTOVER);
        $this->assertStringContainsString('DNS: not pointed to Pantheon yet', $this->plain());
    }

    public function testReadyShowsEveryRecordTheCutoverOptionsAndTheRecheckPrompt(): void
    {
        $this->verifyFixture(F::READY);
        $out = $this->plain();
        $this->assertStringContainsString('Cloudflare status: hostname pending, certificate active (as of 2026-10-11T18:04:05Z)', $out);
        $this->assertStringContainsString('Name:  _cf-custom-hostname.ready.example.com', $out);
        $this->assertStringContainsString('Value: ready.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', $out);
        $this->assertStringContainsString('A: 104.18.1.1 (current: 172.67.1.1)', $out);
        $this->assertStringContainsString('Turning proxying off for this', $out);
        $this->assertStringContainsString('Cloudflare errors 1016 or 1034 may appear briefly', $out);
        $this->assertStringContainsString('Name:  cf-dns-verify.ready.example.com', $out);
        $this->assertStringContainsString('When you\'re ready, switch DNS, then run: terminus gcdn:verify my-site.live ready.example.com', $out);
        $this->assertDoesNotMatchRegularExpression('/\b\d+\s*(hours?|days?)\b/', $out, 'no timeline');
    }

    public function testCandidateWarnsAndPointsToO2o(): void
    {
        $this->verifyFixture(F::CANDIDATE);
        $out = $this->plain();
        $this->assertStringContainsString('This domain is served through Cloudflare today', $out);
        $this->assertStringContainsString('Don\'t switch DNS yet: Pantheon hasn\'t confirmed ownership.', $out);
        $this->assertStringContainsString('Ownership: The hostname is using Cloudflare', $out);
        $this->assertStringContainsString('terminus gcdn:o2o my-site.live candidate.example.com', $out);
    }

    public function testPendingShowsTxtDcvTrafficAndErrors(): void
    {
        $this->verifyFixture(F::PENDING);
        $out = $this->plain();
        $this->assertStringContainsString('Name:  _cf-custom-hostname.pending.example.com', $out);
        $this->assertStringContainsString('Value: pending.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', $out);
        $this->assertStringContainsString('Not ready yet: add the records above', $out);
        $this->assertStringContainsString('Ownership: None of the A or AAAA records', $out);
    }

    public function testZoneHoldSaysContactSupport(): void
    {
        $this->verifyFixture(F::ZONE_HOLD);
        $this->assertStringContainsString('held zone. Contact Pantheon support.', $this->plain());
        // Without the flag, only Cloudflare's own error text mentions the hold.
        $this->verifyFixture(F::ZONE_HOLD, false);
        $this->assertStringNotContainsString('held zone. Contact Pantheon support.', $this->plain());
    }

    public function testDcvComesFromTheTableWhenTheApiHasNone(): void
    {
        $this->verifyFixture(F::PENDING, false);
        $this->assertStringContainsString('Value: pending.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', $this->plain());
    }

    public function testMalformedApiDcvFallsBackToTheTable(): void
    {
        $domain = $this->domain(F::PENDING);
        $domain->challenges->dcv_delegation->val = 'pending.example.com.evil.example.net';
        $this->verify($domain->id, [$domain]);
        $out = $this->plain();
        $this->assertStringContainsString('Value: pending.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', $out);
        $this->assertStringNotContainsString('evil', $out);
    }

    public function testApiDcvWorksForZonesMissingFromTheTable(): void
    {
        $domain = $this->domain(F::CANARY_ZONE);
        $domain->challenges->dcv_delegation = (object) ['key' => '_acme-challenge.canary.example.com', 'val' => 'canary.example.com.0123456789abcdef.dcv.cloudflare.com'];
        $this->verify($domain->id, [$domain]);
        $this->assertStringContainsString('Value: canary.example.com.0123456789abcdef.dcv.cloudflare.com.', $this->plain());
    }

    public function testUnknownZoneWithoutApiDcvSaysContactSupport(): void
    {
        $this->verifyFixture(F::UNKNOWN_ZONE);
        $out = $this->plain();
        $this->assertStringContainsString('Pantheon couldn\'t determine it for this domain. Contact Pantheon support.', $out);
        $this->assertStringNotContainsString('DcvZones', $out);
    }

    public function testDomainsGetErrorExitsOneWithoutWrites(): void
    {
        $this->cmd = new TestVerifyCommand();
        $this->wire($this->cmd);
        $this->on('GET', self::DOMAINS_PATH, $this->apiResult(500, null));
        $this->assertSame(1, $this->cmd->verify('my-site.live', 'pending.example.com', ['method' => null, 'no-recheck' => false]));
        $this->assertStringContainsString('Could not fetch the domain status.', $this->plain());
        $this->assertSame([], $this->writes());
    }

    public function testDomainNotFoundExitsOneWithoutWrites(): void
    {
        $this->assertSame(1, $this->verify('nope.example.com', [$this->domain(F::PENDING)]));
        $this->assertStringContainsString('Domain not found on my-site.live. Add it first:', $this->plain());
        $this->assertSame([], $this->writes());
    }

    // The recheck.

    public function testNoRecheckMakesNoWrites(): void
    {
        $this->assertSame(1, $this->verifyFixture(F::PENDING, true, ['no-recheck' => true]));
        $this->assertSame($this->readOnly(), $this->callLog());
        $this->assertStringContainsString('Recheck skipped (--no-recheck).', $this->plain());
    }

    /** A recent poll only read the status; it doesn't replace the recheck. */
    public function testRecheckIsNotSkippedRightAfterACloudflareRead(): void
    {
        $domain = $this->domain(F::PENDING);
        $this->verify($domain->id, [$domain], [], [], fn($cmd) => $cmd->clock = TestVerifyCommand::OBSERVED_AT + 10);
        $this->assertSame($this->recheckAndPost($domain->id), $this->callLog());
        $this->assertStringNotContainsString('Recheck skipped', $this->plain());
    }

    public function testRecheckCooldownOnThisMachine(): void
    {
        $config = $this->useCacheDir();
        $domain = $this->domain(F::PENDING);
        $start = TestVerifyCommand::OBSERVED_AT + 3600;

        $this->verify($domain->id, [$domain], [], $config, fn($cmd) => $cmd->clock = $start);
        $this->assertCount(2, $this->writes(), 'first run rechecks');
        $this->assertFileExists($this->cacheDir . '/gcdn-recheck.json');

        $this->verify($domain->id, [$domain], [], $config, fn($cmd) => $cmd->clock = $start + 30);
        $this->assertSame($this->postOnly($domain->id), $this->callLog());
        $this->assertStringContainsString('Recheck skipped: one was requested from this machine 30 seconds ago.', $this->plain());

        $this->verify('PENDING.example.com', [$domain], [], $config, fn($cmd) => $cmd->clock = $start + 30);
        $this->assertCount(1, $this->writes(), 'the cooldown ignores case');

        $this->verify($domain->id, [$domain], [], $config, fn($cmd) => $cmd->clock = $start + 60);
        $this->assertCount(2, $this->writes(), 'rechecks again after a minute');
    }

    public function testCooldownIsPerHostname(): void
    {
        $config = $this->useCacheDir();
        $a = $this->domain(F::PENDING);
        $b = $this->domain(F::CDN_BOTH);
        $this->verify($a->id, [$a, $b], [], $config);
        $this->verify($b->id, [$a, $b], [], $config);
        $this->assertSame($this->recheckAndPost($b->id), $this->callLog());
    }

    public function testUnwritableCacheStillRechecks(): void
    {
        $domain = $this->domain(F::PENDING);
        $this->verify($domain->id, [$domain], [], ['cache_dir' => '/dev/null/nope']);
        $this->assertCount(2, $this->writes());
    }

    public function testRecheckFailureStillShowsTheStatus(): void
    {
        $this->cmd = new TestVerifyCommand();
        $this->wire($this->cmd);
        $this->domainsList($this->domain(F::PENDING));
        $this->on('PATCH', self::PATCH_PATH, function () {
            throw new TerminusException('HTTP request has failed with error "Maximum retry attempts reached".');
        });
        $this->on('POST', self::VERIFY_PATH, $this->apiResult(200, (object) ['verified' => false]));
        $this->assertSame(1, $this->cmd->verify('my-site.live', 'pending.example.com', ['method' => null, 'no-recheck' => false]));
        $this->assertStringContainsString('Cloudflare: ownership and certificate pending', $this->plain());
        $this->assertStringContainsString('warning: Recheck request failed', $this->logger->text());
    }

    // --method (P2): it now changes verify_method through the recheck PATCH.

    public function testP2MethodHttpSwitchesThroughTheRecheck(): void
    {
        $this->verifyFixture(F::PENDING, true, ['method' => 'http']);
        $this->assertSame(['verify_method' => 'http'], (array) $this->writes()[0][2]['form_params']);
        $this->assertStringContainsString('Validation method switched to HTTP.', $this->plain());
    }

    public function testP2MethodChangeIgnoresTheCooldown(): void
    {
        $config = $this->useCacheDir();
        $domain = $this->domain(F::PENDING);
        $start = TestVerifyCommand::OBSERVED_AT + 3600;
        $this->verify($domain->id, [$domain], [], $config, fn($cmd) => $cmd->clock = $start);
        $this->verify($domain->id, [$domain], ['method' => 'http'], $config, fn($cmd) => $cmd->clock = $start + 10);
        $this->assertSame(['verify_method' => 'http'], (array) $this->writes()[0][2]['form_params']);
    }

    public function testP2SameMethodIsAPlainRecheck(): void
    {
        $this->verifyFixture(F::PENDING, true, ['method' => 'dns']);
        $this->assertEquals((object) [], $this->writes()[0][2]['form_params']);
    }

    public function testP2InvalidMethodFailsBeforeAnyRequest(): void
    {
        $this->cmd = new TestVerifyCommand();
        $this->wire($this->cmd);
        try {
            $this->cmd->verify('my-site.live', 'pending.example.com', ['method' => 'smtp', 'no-recheck' => false]);
            $this->fail('expected exception');
        } catch (TerminusException $e) {
            $this->assertStringContainsString('Invalid method', $e->getMessage());
        }
        $this->assertSame(['SITES.GET my-site', 'SITES.GET my-site'], $this->callLog());
    }

    public function testP2MethodOnActiveDomainChangesNothing(): void
    {
        $this->verifyFixture(F::ACTIVE, true, ['method' => 'http']);
        $this->assertSame($this->postOnly('active.example.com'), $this->callLog());
        $this->assertStringContainsString('already active; the validation method was not changed', $this->plain());
    }

    public function testP2MethodOnFastlyIsIgnored(): void
    {
        $this->verifyFixture(F::FASTLY, true, ['method' => 'http']);
        $this->assertSame([], $this->writes());
        $this->assertStringContainsString('--method only applies to GCDN custom domains', $this->logger->text());
    }

    /** P15: HTTP validation is refused for domains served through Cloudflare. */
    public function testP15HttpRefusedForCandidateAndReady(): void
    {
        foreach ([F::CANDIDATE, F::READY] as $fixture) {
            $this->verifyFixture($fixture, true, ['method' => 'http']);
            $this->assertEquals((object) [], $this->writes()[0][2]['form_params'], $fixture);
            $this->assertStringContainsString('keeping DNS validation', $this->plain());
        }
    }

    // Regression tests for the verification-flow bugs.

    /** P1: no POST (or PATCH) for Fastly or platform domains. */
    public function testP1NoWritesForFastlyOrPlatform(): void
    {
        foreach ([F::FASTLY, F::PLATFORM] as $fixture) {
            $this->assertSame(0, $this->verifyFixture($fixture));
            $this->assertSame($this->readOnly(), $this->callLog(), $fixture);
        }
    }

    /** P3: ownership done, certificate pending: no request to re-add the TXT, and cert_errors shown. */
    public function testP3CertPendingShowsCertErrorsNotTheTxt(): void
    {
        $this->verifyFixture(F::PENDING_CERT);
        $out = $this->plain();
        $this->assertStringNotContainsString('_cf-custom-hostname', $out);
        $this->assertStringContainsString('Certificate: The certificate authority could not validate', $out);
    }

    /** P4: a failed POST still prints the status. */
    public function testP4PostFailureStillShowsTheStatus(): void
    {
        $this->cmd = new TestVerifyCommand();
        $this->wire($this->cmd);
        $this->domainsList($this->domain(F::ACTIVE));
        $this->on('POST', self::VERIFY_PATH, function () {
            throw new TerminusException('HTTP request has failed with error "Maximum retry attempts reached".');
        });
        $this->assertSame(0, $this->cmd->verify('my-site.live', 'active.example.com', ['method' => null, 'no-recheck' => false]));
        $this->assertStringContainsString('Cloudflare: ownership and certificate active', $this->plain());
        $this->assertStringContainsString('warning: Could not notify Pantheon', $this->logger->text());
    }

    public function testP4PostErrorStatusStillShowsTheStatus(): void
    {
        $this->cmd = new TestVerifyCommand();
        $this->wire($this->cmd);
        $this->domainsList($this->domain(F::ACTIVE));
        $this->on('POST', self::VERIFY_PATH, $this->apiResult(503, ''));
        $this->assertSame(0, $this->cmd->verify('my-site.live', 'active.example.com', ['method' => null, 'no-recheck' => false]));
        $this->assertStringContainsString('warning: Could not notify Pantheon (HTTP 503)', $this->logger->text());
    }

    /** P13: cdn "" is "status unavailable", not "not on GCDN". */
    public function testP13BlankCdnIsUnavailable(): void
    {
        $this->assertSame(1, $this->verifyFixture(F::CDN_BLANK));
        $this->assertStringNotContainsString('not on GCDN', $this->plain());
    }

    /** P14: the domain match ignores case. */
    public function testP14MixedCaseDomainIsFound(): void
    {
        $domain = $this->domain(F::PENDING);
        $this->verify('Pending.Example.COM', [$domain]);
        $this->assertSame($this->recheckAndPost('pending.example.com'), $this->callLog());
    }

    /** P16: no ANSI codes unless the output is decorated. */
    public function testP16AnsiOnlyWhenDecorated(): void
    {
        $this->verifyFixture(F::PENDING);
        $this->assertStringNotContainsString("\033[", $this->out->fetch());

        $this->cmd = new TestVerifyCommand();
        $this->wire($this->cmd);
        $decorated = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        $this->cmd->setOutput($decorated);
        $this->domainsList($this->domain(F::PENDING));
        $this->on('PATCH', self::PATCH_PATH, $this->apiResult(200, 'ok'));
        $this->on('POST', self::VERIFY_PATH, $this->apiResult(200, (object) []));
        $this->cmd->verify('my-site.live', 'pending.example.com', ['method' => null, 'no-recheck' => false]);
        $this->assertStringContainsString("\033[", $decorated->fetch());
    }

    /** P17: an empty ownership value never prints a blank "Value:". */
    public function testP17EmptyOwnershipValue(): void
    {
        $domain = $this->domain(F::PENDING);
        $domain->challenges->ownership_txt->val = '';
        $this->verify($domain->id, [$domain]);
        $out = $this->plain();
        $this->assertDoesNotMatchRegularExpression('/Value: *\n/', $out);
        $this->assertStringContainsString('Ownership TXT record: not available yet.', $out);
    }

    // The www warning (ready domains only).

    public function testWwwWarningWhenWwwIsACnameToTheApex(): void
    {
        $domain = $this->domain(F::READY);
        $this->verify($domain->id, [$domain], [], [], fn($cmd) => $cmd->cnames = ['www.ready.example.com' => 'ready.example.com.']);
        $this->assertStringContainsString('www.ready.example.com follows your root domain', $this->plain());
    }

    public function testWwwWarningWhenWwwResolvesLikeTheApex(): void
    {
        $domain = $this->domain(F::READY);
        $this->verify($domain->id, [$domain], [], [], function ($cmd) {
            $cmd->addresses = ['www.ready.example.com' => ['172.67.1.1'], 'ready.example.com' => ['172.67.1.1']];
        });
        $this->assertStringContainsString('www.ready.example.com follows your root domain', $this->plain());
    }

    public function testNoWwwWarningWhenWwwIsOnTheSite(): void
    {
        $domain = $this->domain(F::READY);
        $www = clone $domain;
        $www->id = 'www.ready.example.com';
        $this->verify($domain->id, [$domain, $www], [], [], fn($cmd) => $cmd->cnames = ['www.ready.example.com' => 'ready.example.com']);
        $this->assertStringNotContainsString('follows your root domain', $this->plain());
        $this->assertSame([], $this->cmd->lookups);
    }

    public function testNoWwwWarningWhenWwwIsSeparate(): void
    {
        $domain = $this->domain(F::READY);
        $this->verify($domain->id, [$domain]);
        $this->assertStringNotContainsString('follows your root domain', $this->plain());
    }

    public function testDnsLookupsOnlyForReadyDomains(): void
    {
        foreach ([F::PENDING, F::CANDIDATE, F::ACTIVE] as $fixture) {
            $this->verifyFixture($fixture);
            $this->assertSame([], $this->cmd->lookups, $fixture);
        }
    }
}

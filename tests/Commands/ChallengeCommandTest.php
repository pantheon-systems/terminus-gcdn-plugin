<?php

namespace Pantheon\TerminusGCDN\Tests\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusGCDN\Commands\ChallengeCommand;
use Pantheon\TerminusGCDN\Tests\Support\CommandHarness;
use Pantheon\TerminusGCDN\Tests\Support\DomainFixtures as F;

/**
 * gcdn:challenge, pinned to the behavior at 0.4.0. A `--method` toggle is a
 * PATCH that queues an UPDATE in policy-docs (ssl resent to Cloudflare), so
 * every test asserts the exact writes.
 */
final class ChallengeCommandTest extends CommandHarness
{
    private const PATCH_PATH = '#^sites/[^/]+/environments/live/hostnames/[^/]+$#';

    private function challenge(?string $name, array $options, object ...$domains)
    {
        $cmd = new ChallengeCommand();
        $this->wire($cmd);
        $this->domainsList(...$domains);
        $this->on('PATCH', self::PATCH_PATH, $this->apiResult(200, 'Hostname updated successfully.'));
        return $cmd->challenge('my-site.live', $name, $options + ['method' => null, 'all' => false]);
    }

    private function patchedHosts(): array
    {
        return array_map(fn($call) => rawurldecode(basename($call[1])), $this->writes());
    }

    public function testShowDnsMethodIsReadOnlyAndShowsDcvFirst(): void
    {
        foreach ([true, false] as $withKeys) {
            $this->calls = [];
            $this->script = [];
            $this->assertNull($this->challenge('pending.example.com', [], $this->domain(F::PENDING, $withKeys)));
            $out = $this->plain();
            $this->assertSame([], $this->writes());
            $this->assertStringContainsString('Current method: DNS', $out);
            $this->assertLessThan(
                strpos($out, 'TXT Record — Certificate Validation'),
                strpos($out, 'DCV Delegation CNAME (Recommended)')
            );
            $this->assertStringContainsString('Value: pending.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', $out);
        }
    }

    public function testShowHttpMethodShowsCutoverAndServeFile(): void
    {
        $domain = $this->domain(F::PENDING);
        $domain->verify_method = 'http';
        $this->challenge('pending.example.com', [], $domain);
        $out = $this->plain();
        $this->assertStringContainsString('Current method: HTTP', $out);
        $this->assertStringContainsString('CNAME pending.example.com -> fe.cfp1c.edge.pantheon.io.', $out);
        $this->assertStringContainsString('Body:  abc123.def456', $out);
    }

    public function testToggleSendsVerifyMethodPatch(): void
    {
        $this->challenge('pending.example.com', ['method' => 'http'], $this->domain(F::PENDING));
        $writes = $this->writes();
        $this->assertCount(1, $writes);
        $this->assertSame('sites/' . self::SITE_ID . '/environments/live/hostnames/pending.example.com', $writes[0][1]);
        $this->assertSame(['verify_method' => 'http'], $writes[0][2]['form_params']);
        $this->assertStringContainsString('pending.example.com — switched to HTTP', $this->plain());
    }

    public function testVerifiedHostnameIsNeverPatched(): void
    {
        $this->challenge('active.example.com', ['method' => 'http'], $this->domain(F::ACTIVE));
        $this->assertSame([], $this->writes());
        $this->assertStringContainsString('skipped (verified)', $this->plain());
    }

    public function testSameMethodIsSkipped(): void
    {
        $this->challenge('pending.example.com', ['method' => 'dns'], $this->domain(F::PENDING));
        $this->assertSame([], $this->writes());
        $this->assertStringContainsString('skipped (already DNS)', $this->plain());
    }

    public function testAllTogglesOnlyUnverifiedCustomCloudflareHostnames(): void
    {
        $this->challenge(null, ['method' => 'http', 'all' => true],
            $this->domain(F::PENDING),
            $this->domain(F::ACTIVE),
            $this->domain(F::FASTLY),
            $this->domain(F::PLATFORM),
            $this->domain(F::CDN_BOTH)
        );
        $this->assertSame(['pending.example.com', 'both.example.com'], $this->patchedHosts());
        $out = $this->plain();
        $this->assertStringContainsString('Toggled:                 2', $out);
        $this->assertStringContainsString('Skipped (verified):      1', $out);
    }

    public function testValidationFailsBeforeAnyRequest(): void
    {
        $cases = [
            [null, ['method' => 'smtp', 'all' => false], 'Invalid method'],
            [null, ['method' => null, 'all' => true], '--all requires --method'],
            [null, ['method' => null, 'all' => false], 'Provide a domain name'],
        ];
        foreach ($cases as [$name, $options, $message]) {
            $cmd = new ChallengeCommand();
            $this->calls = [];
            $this->wire($cmd);
            try {
                $cmd->challenge('my-site.live', $name, $options);
                $this->fail('expected exception');
            } catch (TerminusException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
            $this->assertSame([], array_filter($this->calls, fn($c) => $c[0] !== 'SITES.GET'));
        }
    }

    public function testUnknownDomainThrows(): void
    {
        $this->expectException(TerminusException::class);
        $this->expectExceptionMessage('Domain nope.example.com not found on my-site.live.');
        $this->challenge('nope.example.com', [], $this->domain(F::PENDING));
    }

    public function testFastlyDomainIsNotToggled(): void
    {
        $this->challenge('fastly.example.com', ['method' => 'http'], $this->domain(F::FASTLY));
        $this->assertSame([], $this->writes());
        $this->assertStringContainsString('This domain is not on Cloudflare.', $this->plain());
    }

    public function testPatchExceptionIsReportedPerHostname(): void
    {
        $cmd = new ChallengeCommand();
        $this->wire($cmd);
        $this->domainsList($this->domain(F::PENDING));
        $this->on('PATCH', self::PATCH_PATH, function () {
            throw new TerminusException('HTTP request has failed with error "Maximum retry attempts reached".');
        });
        $cmd->challenge('my-site.live', null, ['method' => 'http', 'all' => true]);
        $out = $this->plain();
        $this->assertStringContainsString('pending.example.com — error (HTTP request has failed', $out);
        $this->assertStringContainsString('Errors:                  1', $out);
    }

    /** P5: a verified hostname still shows the stale cert TXT. */
    public function testP5VerifiedHostnameStillShowsCertTxt(): void
    {
        $this->challenge('active.example.com', [], $this->domain(F::ACTIVE));
        $this->assertStringContainsString('TXT Record — Certificate Validation (Not recommended — value rotates)', $this->plain());
    }

    /** P6: after a toggle, the records shown come from the GET made before the PATCH. */
    public function testP6RecordsAfterToggleAreFromBeforeThePatch(): void
    {
        $this->challenge('pending.example.com', ['method' => 'dns'], (function () {
            $domain = $this->domain(F::PENDING);
            $domain->verify_method = 'http';
            return $domain;
        })());
        $this->assertCount(1, array_filter($this->calls, fn($c) => $c[0] === 'GET'));
        $this->assertStringContainsString('Value: cert-token-', $this->plain());
    }

    /** P7: a platform hostname can be toggled with a single domain argument. */
    public function testP7PlatformHostnameIsToggled(): void
    {
        $this->challenge('live-my-site.pantheonsite.io', ['method' => 'dns'], $this->domain(F::PLATFORM));
        $this->assertSame(['live-my-site.pantheonsite.io'], $this->patchedHosts());
    }

    /** P14: the domain match is case-sensitive. */
    public function testP14MixedCaseDomainIsNotFound(): void
    {
        $this->expectException(TerminusException::class);
        $this->challenge('Pending.Example.com', [], $this->domain(F::PENDING));
    }

    /** P15: the guard checks only `verified`, so ready hostnames are PATCHed and candidates switched to HTTP. */
    public function testP15ReadyAndCandidateHostnamesArePatched(): void
    {
        $this->challenge(null, ['method' => 'http', 'all' => true], $this->domain(F::READY), $this->domain(F::CANDIDATE));
        $this->assertSame(['ready.example.com', 'candidate.example.com'], $this->patchedHosts());
    }

    /** P16: a failed toggle exits 0. */
    public function testP16FailedToggleExitsZero(): void
    {
        $cmd = new ChallengeCommand();
        $this->wire($cmd);
        $this->domainsList($this->domain(F::PENDING));
        $this->on('PATCH', self::PATCH_PATH, $this->apiResult(500, 'Something went wrong. Please try again.'));
        $this->assertNull($cmd->challenge('my-site.live', 'pending.example.com', ['method' => 'http', 'all' => false]));
        $this->assertStringContainsString('error (PATCH failed)', $this->plain());
    }
}

<?php

namespace Pantheon\TerminusGCDN\Tests\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusGCDN\Commands\ChallengeCommand;
use Pantheon\TerminusGCDN\Tests\Support\CommandHarness;
use Pantheon\TerminusGCDN\Tests\Support\DomainFixtures as F;

/**
 * gcdn:challenge. A `--method` toggle is a PATCH that queues an UPDATE in
 * policy-docs (ssl resent to Cloudflare), so every test asserts the exact
 * writes.
 */
final class ChallengeCommandTest extends CommandHarness
{
    private const PATCH_PATH = '#^sites/[^/]+/environments/live/hostnames/[^/]+$#';

    private function challenge(?string $name, array $options, object ...$domains)
    {
        $this->calls = [];
        $this->script = [];
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

    public function testShowDnsMethodIsReadOnlyAndShowsDcvAndTxt(): void
    {
        foreach ([true, false] as $withKeys) {
            $this->assertSame(0, $this->challenge('pending.example.com', [], $this->domain(F::PENDING, $withKeys)));
            $out = $this->plain();
            $this->assertSame([], $this->writes());
            $this->assertStringContainsString('Current method: DNS', $out);
            $this->assertStringContainsString('Value: pending.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', $out);
            $this->assertStringContainsString('Name:  _cf-custom-hostname.pending.example.com', $out);
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

    public function testShowLegacyHttpToken(): void
    {
        $domain = $this->domain(F::PENDING);
        $domain->verify_method = 'http';
        unset($domain->challenges->cert_http);
        $domain->acme_preauthorization_challenges = (object) ['http-01' => (object) ['verification_key' => 'http://pending.example.com/.well-known/acme-challenge/x', 'verification_value' => 'legacy.http']];
        $this->challenge('pending.example.com', [], $domain);
        $this->assertStringContainsString('Body:  legacy.http', $this->plain());
    }

    public function testToggleSendsVerifyMethodPatchThenReReads(): void
    {
        $this->assertSame(0, $this->challenge('pending.example.com', ['method' => 'http'], $this->domain(F::PENDING)));
        $writes = $this->writes();
        $this->assertCount(1, $writes);
        $this->assertSame('sites/' . self::SITE_ID . '/environments/live/hostnames/pending.example.com', $writes[0][1]);
        $this->assertSame(['verify_method' => 'http'], $writes[0][2]['form_params']);
        $out = $this->plain();
        $this->assertStringContainsString('pending.example.com — switched to HTTP', $out);
        $this->assertStringContainsString('Current method: HTTP', $out);
    }

    public function testActiveHostnameIsNeverPatched(): void
    {
        $this->assertSame(0, $this->challenge('active.example.com', ['method' => 'http'], $this->domain(F::ACTIVE)));
        $this->assertSame([], $this->writes());
        $this->assertStringContainsString('skipped (active)', $this->plain());
    }

    public function testSameMethodIsSkipped(): void
    {
        $this->challenge('pending.example.com', ['method' => 'dns'], $this->domain(F::PENDING));
        $this->assertSame([], $this->writes());
        $this->assertStringContainsString('skipped (already DNS)', $this->plain());
    }

    public function testAllTogglesOnlyEligibleCustomCloudflareHostnames(): void
    {
        $result = $this->challenge(null, ['method' => 'http', 'all' => true],
            $this->domain(F::PENDING),
            $this->domain(F::ACTIVE),
            $this->domain(F::READY),
            $this->domain(F::CANDIDATE),
            $this->domain(F::FASTLY),
            $this->domain(F::PLATFORM),
            $this->domain(F::CDN_BLANK),
            $this->domain(F::NO_CHALLENGES),
            $this->domain(F::CDN_BOTH)
        );
        $this->assertSame(0, $result);
        $this->assertSame(['pending.example.com', 'both.example.com'], $this->patchedHosts());
        $out = $this->plain();
        $this->assertStringContainsString('Toggled:                  2', $out);
        $this->assertStringContainsString('Skipped (active):         1', $out);
        $this->assertStringContainsString('Skipped (records ready):  1', $out);
        $this->assertStringContainsString('Skipped (via Cloudflare): 1', $out);
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
        $this->assertSame(1, $this->challenge('fastly.example.com', ['method' => 'http'], $this->domain(F::FASTLY)));
        $this->assertSame([], $this->writes());
        $this->assertStringContainsString('This domain is not on Cloudflare.', $this->plain());
        $this->assertSame(0, $this->challenge('fastly.example.com', [], $this->domain(F::FASTLY)));
    }

    public function testUnavailableAndMissingStatusExitOne(): void
    {
        $this->assertSame(1, $this->challenge('blank.example.com', ['method' => 'http'], $this->domain(F::CDN_BLANK)));
        $this->assertStringContainsString('Status unavailable', $this->plain());
        $this->assertSame(1, $this->challenge('nochallenges.example.com', [], $this->domain(F::NO_CHALLENGES)));
        $this->assertStringContainsString('Status not available yet', $this->plain());
        $this->assertSame([], $this->writes());
    }

    public function testPatchExceptionIsReportedPerHostnameAndExitsOne(): void
    {
        $cmd = new ChallengeCommand();
        $this->wire($cmd);
        $this->domainsList($this->domain(F::PENDING));
        $this->on('PATCH', self::PATCH_PATH, function () {
            throw new TerminusException('HTTP request has failed with error "Maximum retry attempts reached".');
        });
        $this->assertSame(1, $cmd->challenge('my-site.live', null, ['method' => 'http', 'all' => true]));
        $out = $this->plain();
        $this->assertStringContainsString('pending.example.com — error (HTTP request has failed', $out);
        $this->assertStringContainsString('Errors:                   1', $out);
    }

    /** P5: never the raw cert TXT, including for active domains. */
    public function testP5NoCertTxt(): void
    {
        foreach ([F::ACTIVE, F::PENDING, F::READY] as $fixture) {
            $domain = $this->domain($fixture);
            $this->challenge($domain->id, [], $domain);
            $this->assertStringNotContainsString('cert-token-', $this->plain(), $fixture);
        }
    }

    /** P6: after a toggle, the records come from a GET made after the PATCH. */
    public function testP6RecordsAfterToggleComeFromAFreshGet(): void
    {
        $before = $this->domain(F::PENDING);
        $before->verify_method = 'http';
        $after = $this->domain(F::PENDING);
        $after->challenges->ownership_txt->val = 'fresh-ownership-value';

        $cmd = new ChallengeCommand();
        $this->wire($cmd);
        $gets = 0;
        $this->on('GET', self::DOMAINS_PATH, function () use (&$gets, $before, $after) {
            return $this->apiResult(200, [$gets++ === 0 ? $before : $after]);
        });
        $this->on('PATCH', self::PATCH_PATH, $this->apiResult(200, 'Hostname updated successfully.'));
        $cmd->challenge('my-site.live', 'pending.example.com', ['method' => 'dns', 'all' => false]);

        $this->assertSame(['GET', 'PATCH', 'GET'], array_values(array_map(fn($c) => $c[0], array_filter($this->calls, fn($c) => $c[0] !== 'SITES.GET'))));
        $out = $this->plain();
        $this->assertStringContainsString('Value: fresh-ownership-value', $out);
        $this->assertStringContainsString('Validation values can change while Cloudflare processes the switch.', $out);
    }

    /** P7: platform domains are never toggled. */
    public function testP7PlatformHostnameIsNotToggled(): void
    {
        $this->assertSame(1, $this->challenge('live-my-site.pantheonsite.io', ['method' => 'dns'], $this->domain(F::PLATFORM)));
        $this->assertSame([], $this->writes());
        $this->assertStringContainsString('Managed by Pantheon', $this->plain());
    }

    /** P14: the domain match ignores case. */
    public function testP14MixedCaseDomainIsFound(): void
    {
        $this->assertSame(0, $this->challenge('Pending.Example.com', [], $this->domain(F::PENDING)));
        $this->assertStringContainsString("pending.example.com\n------", $this->plain());
    }

    /** P15: ready domains aren't PATCHed, and candidates aren't switched to HTTP. */
    public function testP15ReadyAndCandidateGuards(): void
    {
        $this->challenge(null, ['method' => 'http', 'all' => true], $this->domain(F::READY), $this->domain(F::CANDIDATE));
        $this->assertSame([], $this->writes());

        $this->challenge('ready.example.com', ['method' => 'http'], $this->domain(F::READY));
        $this->assertSame([], $this->writes());
        $this->assertStringContainsString('skipped (DNS records ready)', $this->plain());

        // A candidate can still move to DNS.
        $candidate = $this->domain(F::CANDIDATE);
        $candidate->verify_method = 'http';
        $this->challenge('candidate.example.com', ['method' => 'dns'], $candidate);
        $this->assertSame(['candidate.example.com'], $this->patchedHosts());
    }

    /** P16: a failed toggle exits 1. */
    public function testP16FailedToggleExitsOne(): void
    {
        $cmd = new ChallengeCommand();
        $this->wire($cmd);
        $this->domainsList($this->domain(F::PENDING));
        $this->on('PATCH', self::PATCH_PATH, $this->apiResult(500, 'Something went wrong. Please try again.'));
        $this->assertSame(1, $cmd->challenge('my-site.live', 'pending.example.com', ['method' => 'http', 'all' => false]));
        $this->assertStringContainsString('error (PATCH failed, HTTP 500)', $this->plain());
    }
}

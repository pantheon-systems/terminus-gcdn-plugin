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

    private function patchOnly(string $host): array
    {
        return ['SITES.GET my-site', 'SITES.GET my-site', self::DOMAINS_GET, 'PATCH ' . $this->hostPath($host)];
    }

    private function readOnly(): array
    {
        return ['SITES.GET my-site', 'SITES.GET my-site', self::DOMAINS_GET];
    }

    /**
     * fixture, keys present?, headline, exit code, writes: recheck (PATCH + POST) | patch | post | none
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
            'ready, moved' => [F::READY_MOVED, true, 'Cloudflare: DNS records ready (confirmed by Pantheon)', 0, 'recheck'],
            'ready, moved, cert lost' => [F::READY_MOVED_CERT_LOST, true, 'Cloudflare: ownership not confirmed yet', 1, 'recheck'],
            'blocked' => [F::BLOCKED, true, 'Cloudflare: action required (status: blocked)', 1, 'none'],
            'deleted' => [F::DELETED, true, 'Cloudflare: DNS records ready (confirmed by Pantheon)', 0, 'recheck'],
            'blocked, keys absent' => [F::BLOCKED, false, 'Cloudflare: ownership and certificate pending', 1, 'recheck'],
            'candidate' => [F::CANDIDATE, true, 'Cloudflare: ownership not confirmed yet', 1, 'recheck'],
            'candidate, keys absent' => [F::CANDIDATE, false, 'Cloudflare: ownership and certificate pending', 1, 'recheck'],
            'zone hold' => [F::ZONE_HOLD, true, 'Cloudflare: ownership and certificate pending', 1, 'recheck'],
            'zone hold, blocked' => [F::ZONE_HOLD_BLOCKED, true, 'Cloudflare: action required (zone hold, status: blocked)', 1, 'patch'],
            'zone hold, blocked, keys absent' => [F::ZONE_HOLD_BLOCKED, false, 'Cloudflare: ownership and certificate pending', 1, 'recheck'],
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
        $expected = ['recheck' => $this->recheckAndPost($domain->id), 'patch' => $this->patchOnly($domain->id), 'post' => $this->postOnly($domain->id), 'none' => $this->readOnly()][$writes];
        $this->assertSame($expected, $this->callLog());
        if ($writes === 'recheck' || $writes === 'patch') {
            $this->assertEquals((object) [], $this->writes()[0][2]['form_params'], 'Force Recheck sends {}');
        }
        // P5: never the rotating cert TXT.
        $this->assertStringNotContainsString('cert-token-', $out);
        $this->assertStringNotContainsString('legacy-cert-1', $out);
    }

    public function testMovedSaysTheRecheckRestartedCloudflaresChecks(): void
    {
        $options = $this->useCacheDir();
        $domain = $this->domain(F::READY_MOVED);
        $this->assertSame(0, $this->verify($domain->id, [$domain], [], $options));
        $out = $this->plain();
        $this->assertStringContainsString('Cloudflare paused its checks on this domain (status: moved)', $out);
        $this->assertStringContainsString('This run asked Cloudflare to check again.', $out);
        $this->assertStringContainsString('Switch DNS soon: Cloudflare pauses again after about 11 days', $out);
        $this->assertStringContainsString('After you choose an option and switch DNS, wait about 5 minutes', $out);
    }

    /** Seen in production, 2026-10-29: a recheck took a domain from deleted to active. */
    public function testDeletedIsRestoredByTheRecheck(): void
    {
        $options = $this->useCacheDir();
        $domain = $this->domain(F::DELETED);
        $this->assertSame(0, $this->verify($domain->id, [$domain], [], $options));
        $out = $this->plain();
        $this->assertSame($this->recheckAndPost($domain->id), $this->callLog());
        $this->assertStringContainsString('Cloudflare marked this domain deleted (status: deleted)', $out);
        $this->assertStringContainsString('This run asked Cloudflare to restore it.', $out);
        $this->assertStringContainsString('If it still shows deleted after a recheck, contact Pantheon support.', $out);
        $this->assertStringNotContainsString('action required', $out);
        $this->assertStringNotContainsString('Cloudflare paused its checks', $out);
    }

    public function testDeletedWithNoRecheckPointsToTheRecheck(): void
    {
        $this->assertSame(0, $this->verifyFixture(F::DELETED, true, ['no-recheck' => true]));
        $this->assertSame($this->readOnly(), $this->callLog());
        $this->assertStringContainsString('A recheck restores it: run "terminus gcdn:verify my-site.live deleted.example.com".', $this->plain());
    }

    public function testMovedWithNoRecheckPointsToTheRecheck(): void
    {
        $this->assertSame(0, $this->verifyFixture(F::READY_MOVED, true, ['no-recheck' => true]));
        $out = $this->plain();
        $this->assertSame($this->readOnly(), $this->callLog());
        $this->assertStringContainsString('To restart the checks, run "terminus gcdn:verify my-site.live moved.example.com".', $out);
        $this->assertStringNotContainsString('This run asked Cloudflare', $out);
    }

    public function testMovedWithLostCertIsNotReady(): void
    {
        $this->verifyFixture(F::READY_MOVED_CERT_LOST);
        $out = $this->plain();
        $this->assertStringContainsString('Cloudflare paused its checks', $out);
        $this->assertStringContainsString('Cloudflare removes a domain that stays paused for 7 days.', $out);
        $this->assertStringNotContainsString('Switch DNS soon', $out);
        $this->assertStringContainsString('Don\'t switch DNS yet', $out);
    }

    public function testActionRequiredMakesNoWritesAndShowsNoRecordsToSwitchTo(): void
    {
        foreach ([F::BLOCKED => 'Cloudflare has blocked this domain.'] as $fixture => $reason) {
            $this->assertSame(1, $this->verifyFixture($fixture));
            $out = $this->plain();
            $this->assertSame([], $this->writes());
            $this->assertStringContainsString('Force recheck skipped: a recheck can\'t fix Cloudflare status', $out);
            $this->assertStringContainsString($reason, $out);
            $this->assertStringContainsString('Don\'t switch DNS. Contact Pantheon support and include this output.', $out);
            $this->assertStringNotContainsString('fe.cfp1c.edge.pantheon.io', $out);
            $this->assertStringNotContainsString('_cf-custom-hostname', $out);
        }
    }

    public function testActiveShowsTrafficAndKeepsDcv(): void
    {
        $this->verifyFixture(F::ACTIVE);
        $out = $this->plain();
        $this->assertStringContainsString('3. Switch traffic to Pantheon: done', $out);
        $this->assertStringContainsString('Next: nothing. The domain is active and DNS points to Pantheon.', $out);
        $this->assertRow($out, 'CNAME', 'active.example.com', 'fe.cfp1c.edge.pantheon.io', 'None: points to Pantheon.');
        $this->assertRow($out, "CNAME", '_acme-challenge.active.example.com', 'active.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', 'None.');
        $this->assertRow($out, '(DNS only)');
        $this->assertStringContainsString('If you use another proxy in front of Pantheon (Cloudflare or otherwise), keep this record in place: without it, your certificate can fail to renew.', $out);
        $this->assertStringContainsString('If you don\'t, we still recommend keeping it: it\'s extra insurance that nothing can interfere with certificate renewals.', $out);
        $this->assertStringNotContainsString('found in DNS', $out, 'lookup failed: no DNS claim');
        $this->assertStringNotContainsString('_cf-custom-hostname', $out);
    }

    public function testTrafficTableGroupsRowsAndLabelsCloudflareAddresses(): void
    {
        // frankensteingym.com, proxied by its own Cloudflare zone (2026-11-03).
        $domain = $this->domain(F::READY);
        $row = fn($type, $value, $detected) => (object) ['type' => $type, 'target_value' => $value, 'detected_value' => $detected, 'status' => 'action_required'];
        $domain->dns_status_details->dns_records = [
            $row('A', '', '104.21.39.242'),
            $row('A', '104.18.1.1', '172.67.172.41'),
            $row('AAAA', '', '2606:4700:3032::6815:27f2'),
            $row('AAAA', '2606:4700::6812:101', '2606:4700:3033::ac43:ac29'),
        ];
        $this->verify($domain->id, [$domain], ['no-recheck' => true]);
        $out = $this->plain();
        $this->assertRow($out, 'Type', 'Name', 'Value');
        $this->assertRow($out, 'Use one option, not both.');
        $this->assertRow($out, 'Option 1: CNAME');
        $this->assertRow($out, 'CNAME', 'ready.example.com', 'fe.cfp1c.edge.pantheon.io', 'Add it, Proxied. In your own Cloudflare account, delete the A and AAAA records for this name first');
        $this->assertRow($out, '(Proxied)');
        $this->assertRow($out, 'Option 2: A and AAAA records');
        $this->assertRow($out, 'A', 'ready.example.com', '104.18.1.1', "In your own Cloudflare account, use option 1 instead. DNS shows Cloudflare's proxy addresses: 104.21.39.242, 172.67.172.41.");
        $this->assertRow($out, 'AAAA', 'ready.example.com', '2606:4700::6812:101', "In your own Cloudflare account, use option 1 instead. DNS shows Cloudflare's proxy addresses: 2606:4700:3032::6815:27f2, 2606:4700:3033::ac43:ac29.");
        $this->assertSame(1, substr_count($out, '│ A '), 'one A row, without the empty extra rows');
    }

    public function testActiveBehindCloudflareCountsAsSwitched(): void
    {
        $domain = $this->domain(F::ACTIVE);
        $domain->challenges->cloudflare_routed_o2o_candidate = true;
        $domain->dns_status_details->dns_records = [
            (object) ['type' => 'A', 'target_value' => '104.18.1.1', 'detected_value' => '172.67.172.41', 'status' => 'action_required'],
            (object) ['type' => 'AAAA', 'target_value' => '2606:4700::6812:101', 'detected_value' => '2606:4700:3033::ac43:ac29', 'status' => 'action_required'],
        ];
        $this->verify($domain->id, [$domain]);
        $out = $this->plain();
        $this->assertStringContainsString('3. Switch traffic to Pantheon: done', $out);
        $this->assertStringContainsString('Next: nothing. The domain is active and DNS points to Pantheon.', $out);
        $this->assertRow($out, 'A', 'active.example.com', '104.18.1.1', "None. DNS shows Cloudflare's proxy addresses: 172.67.172.41.");
        $this->assertStringNotContainsString('delete the A and AAAA records', $out, 'already switched');
    }

    public function testCertTxtShowsTheTxtInsteadOfTheCname(): void
    {
        $domain = $this->domain(F::PENDING_CERT);
        $name = '_acme-challenge.' . $domain->id;
        $txt = $domain->challenges->cert_txt->val;

        $this->verify($domain->id, [$domain], ['no-recheck' => true]);
        $this->assertRow($this->plain(), 'CNAME', $name, $domain->challenges->dcv_delegation->val . '.', 'Add this record. Remove any TXT records with this name first: they conflict with it. Can\'t use a CNAME with this name? Run gcdn:verify with --cert-txt for a TXT record instead.');

        $this->verify($domain->id, [$domain], ['no-recheck' => true, 'cert-txt' => true], [], fn($cmd) => $cmd->records = ['CNAME' => [$name => []], 'TXT' => [$name => []]]);
        $out = $this->plain();
        $this->assertStringContainsString('2. Certificate (TXT record): not done', $out);
        $this->assertRow($out, 'TXT', $name, $txt, 'Add this record: not in DNS yet.');
        $this->assertStringNotContainsString('dcv.cloudflare.com', $out);
        $this->assertStringContainsString('This value changes with every certificate order, so add it as shown today.', $out);
        $this->assertStringContainsString('If a renewal needs a TXT again, it has a new value: contact Pantheon Support.', $out);

        $this->verify($domain->id, [$domain], ['no-recheck' => true, 'cert-txt' => true], [], fn($cmd) => $cmd->records = ['CNAME' => [$name => []], 'TXT' => [$name => [$txt]]]);
        $this->assertRow($this->plain(), 'TXT', $name, $txt, 'None: found in DNS.');

        $this->verify($domain->id, [$domain], ['no-recheck' => true, 'cert-txt' => true], [], fn($cmd) => $cmd->records = ['CNAME' => [$name => ['x.dcv.cloudflare.com']]]);
        $out = $this->plain();
        $this->assertRow($out, 'TXT', $name, $txt, 'Delete the CNAME with this name first: the two can\'t share a name.');
    }

    public function testCertTxtRecheckWaitsForTheTxt(): void
    {
        $domain = $this->domain(F::PENDING_CERT);
        $name = '_acme-challenge.' . $domain->id;
        $this->verify($domain->id, [$domain], ['cert-txt' => true], [], fn($cmd) => $cmd->records = ['CNAME' => [$name => []], 'TXT' => [$name => []]]);
        $this->assertStringContainsString('the step 2 TXT record isn\'t in DNS with the value shown yet', $this->plain());
    }

    public function testCertTxtOnAnActiveDomain(): void
    {
        $this->verifyFixture(F::ACTIVE, true, ['cert-txt' => true]);
        $out = $this->plain();
        $this->assertStringContainsString('2. Certificate (DCV CNAME): done', $out);
        $this->assertStringContainsString('No TXT record needed now: the certificate is active, and the CNAME is what renewals can rely on.', $out);
    }

    public function testTablesWrapToTheTerminal(): void
    {
        putenv('COLUMNS=100');
        $this->verifyFixture(F::READY, true, ['no-recheck' => true]);
        $out = $this->plain();
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^[│┌├└]/u', $line)) {
                $this->assertLessThanOrEqual(100, mb_strlen($line), $line);
            }
        }
        // Only when it must, a value wraps, after a dot.
        $this->assertMatchesRegularExpression('/│ ready\.example\.com\.dc5ecddca9c0e249\.dcv\. *│$/mu', $out);
        $this->assertMatchesRegularExpression('/│ cloudflare\.com\. *│$/mu', $out);

        // Wide enough, and names and values stay whole, so they copy in one piece.
        putenv('COLUMNS=120');
        $this->verifyFixture(F::READY, true, ['no-recheck' => true]);
        $this->assertRow($this->plain(), "CNAME", '_acme-challenge.ready.example.com', 'ready.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.');
    }

    public function testATableWithoutRecordsHasNoColumnHeader(): void
    {
        $this->verifyFixture(F::ACTIVE);
        $out = $this->plain();
        $step1 = substr($out, strpos($out, '1. Ownership'), strpos($out, '2. Certificate') - strpos($out, '1. Ownership'));
        $this->assertStringContainsString('None: Cloudflare has confirmed ownership.', $step1);
        $this->assertStringNotContainsString('Action required', $step1);
    }

    public function testDoneDcvAdviceFollowsDns(): void
    {
        $domain = $this->domain(F::ACTIVE);
        $dcv = '_acme-challenge.' . $domain->id;
        $value = $domain->challenges->dcv_delegation->val;
        $proxy = 'If you use another proxy in front of Pantheon (Cloudflare or otherwise)';

        $this->verify($domain->id, [$domain], [], [], fn($cmd) => $cmd->records = ['CNAME' => [$dcv => [$value . '.']]]);
        $out = $this->plain();
        $this->assertRow($out, 'CNAME', $dcv, $value . '.', 'None: found in DNS.');
        $this->assertStringContainsString("{$proxy}, keep this record in place: without it, your certificate can fail to renew.", $out);
        $this->assertSame(["CNAME {$dcv}"], $this->cmd->recordLookups);

        $this->verify($domain->id, [$domain], [], [], fn($cmd) => $cmd->records = ['CNAME' => [$dcv => []]]);
        $out = $this->plain();
        $this->assertRow($out, 'CNAME', $dcv, $value . '.', 'Recommended: add it.');
        $this->assertStringContainsString('2. Certificate (DCV CNAME): done, but the record isn\'t in DNS', $out);
        $this->assertStringContainsString("{$proxy}, add this record: without it, your certificate can fail to renew.", $out);
        $this->assertStringContainsString('If you don\'t, we still recommend adding it: it\'s extra insurance that nothing can interfere with certificate renewals.', $out);

        $this->verify($domain->id, [$domain], [], [], fn($cmd) => $cmd->records = ['CNAME' => [$dcv => ['old.abc123.dcv.cloudflare.com']]]);
        $out = $this->plain();
        $this->assertRow($out, 'CNAME', $dcv, $value . '.', 'Recommended: change it to this value. DNS has: old.abc123.dcv.cloudflare.com (another Cloudflare account\'s certificate validation, likely the current host)');
        $this->assertStringContainsString('2. Certificate (DCV CNAME): done, but DNS has another value', $out);
        $this->assertStringContainsString("{$proxy}, change it to the value above: otherwise, your certificate can fail to renew.", $out);
        $this->assertStringContainsString('If you don\'t, we still recommend changing it:', $out);
    }

    public function testActiveBeforeCutoverSaysNotPointedYet(): void
    {
        $this->verifyFixture(F::ACTIVE_BEFORE_CUTOVER);
        $out = $this->plain();
        $this->assertStringContainsString('3. Switch traffic to Pantheon: not done: switch whenever you\'re ready', $out);
        $this->assertStringContainsString('Next: switch DNS (step 3) whenever you\'re ready, then run: terminus gcdn:verify my-site.live', $out);
    }

    public function testReadyShowsEveryRecordTheCutoverOptionsAndTheRecheckPrompt(): void
    {
        $this->verifyFixture(F::READY);
        $out = $this->plain();
        $this->assertStringContainsString('Cloudflare status: hostname pending, certificate active (as of 2026-10-11T18:04:05Z)', $out);
        $this->assertRow($out, 'TXT', '_cf-custom-hostname.ready.example.com', '0b1c2d3e-own-fd9b5c21', 'None: confirmed by Pantheon.');
        $this->assertRow($out, 'CNAME', '_acme-challenge.ready.example.com', 'ready.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', 'None.');
        $this->assertRow($out, 'A', 'ready.example.com', '104.18.1.1', "In your own Cloudflare account, use option 1 instead. DNS shows Cloudflare's proxy addresses: 172.67.1.1.");
        $this->assertStringContainsString('Add it, Proxied. In your own Cloudflare account, delete the A and AAAA records for this name first', $out);
        $this->assertStringContainsString('Pantheon has confirmed the _cf-custom-hostname TXT record has the correct value', $out);
        $this->assertStringContainsString('See "Issues and Notes" in step 1.', $out);
        // Step 1's Issues and Notes: Cloudflare's errors, then what can be done.
        $step1 = substr($out, strpos($out, '1. Ownership'), strpos($out, '2. Certificate') - strpos($out, '1. Ownership'));
        $this->assertRow($step1, 'Issues and Notes');
        $this->assertRow($step1, 'Notice type', 'Details');
        $this->assertRow($step1, 'Issue: Ownership', '- None of the A or AAAA records are owned by this account');
        $this->assertStringContainsString('- The hostname is using Cloudflare and cannot be activated', $step1);
        $this->assertRow($step1, 'What can be done');
        $this->assertStringContainsString('- If you own or have access to the domain\'s Cloudflare account: you can turn proxying off for this record', $step1);
        $this->assertStringContainsString('pre-validation again. This is optional.', $step1);
        $this->assertStringContainsString('- If your current host uses Cloudflare for SaaS (for example WP Engine or Kinsta): switch DNS to Pantheon', $step1);
        $this->assertStringContainsString('errors 1014, 1016 or 1034 sooner.', $step1);
        $this->assertStringNotContainsString('Potential issues detected', $out);
        $this->assertStringNotContainsString('cf-dns-verify', $out);
        // Step 3's first row: one option, then the run after the switch, the command on its own line.
        $this->assertStringContainsString('│ Use one option, not both. After you choose an option and switch DNS, wait about 5 minutes for it to propagate', $out);
        $this->assertRow($out, 'terminus gcdn:verify my-site.live ready.example.com');
        $this->assertStringContainsString('If you ran gcdn:verify in the last 5 minutes, it shows the latest poll', $out);
        $this->assertStringNotContainsString('After you choose an option and switch DNS, run:', $out);
        $this->assertStringNotContainsString('Remove any TXT records', $out, 'step 2 is done');
        $this->assertDoesNotMatchRegularExpression('/\b\d+\s*(hours?|days?)\b/', $out, 'no timeline');
    }

    public function testCandidateWarnsAndPointsToO2o(): void
    {
        $this->verifyFixture(F::CANDIDATE);
        $out = $this->plain();
        $this->assertStringContainsString('This domain is served through Cloudflare today', $out);
        $this->assertStringContainsString('Don\'t switch DNS yet: Pantheon hasn\'t confirmed ownership.', $out);
        $this->assertStringContainsString('│ 1. Ownership (TXT record): not done: add this record ', $out);
        $this->assertRow($out, 'Issue: Ownership');
        $this->assertStringContainsString('The hostname is using Cloudflare', $out);
        $this->assertStringContainsString('Cloudflare can\'t check it while the domain is served through Cloudflare', $out);
        $this->assertStringContainsString('terminus gcdn:o2o my-site.live candidate.example.com', $out);
    }

    public function testPendingShowsTxtDcvTrafficAndErrors(): void
    {
        $this->verifyFixture(F::PENDING);
        $out = $this->plain();
        $this->assertRow($out, 'Type', 'Name', 'Value');
        $this->assertRow($out, 'TXT', '_cf-custom-hostname.pending.example.com', $this->domain(F::PENDING)->challenges->ownership_txt->val, 'Add this record.');
        $this->assertRow($out, 'CNAME', '_acme-challenge.pending.example.com', 'pending.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', 'Add this record.');
        $this->assertStringContainsString('1. Ownership (TXT record): not done: add this record', $out);
        $this->assertStringContainsString('3. Switch traffic to Pantheon: not yet: after steps 1 and 2', $out);
        $this->assertStringContainsString('Next: Cloudflare is checking the records now.', $out);
        $this->assertRow($out, 'Issue: Ownership', 'None of the A or AAAA records');
        $this->assertStringContainsString('Make sure the TXT record above is in DNS with the value shown, then run gcdn:verify.', $out);
    }

    public function testZoneHoldSendsTheCustomerToTheDomainOwner(): void
    {
        $this->verifyFixture(F::ZONE_HOLD);
        $out = $this->plain();
        $this->assertStringContainsString('Cloudflare reports a zone hold on this domain. The domain owner has to release it', $out);
        $this->assertStringNotContainsString('Contact Pantheon support', $out);
        // Without the flag, only Cloudflare's own error text mentions the hold.
        $this->verifyFixture(F::ZONE_HOLD, false);
        $this->assertStringNotContainsString('Cloudflare reports a zone hold', $this->plain());
    }

    /** Seen in production, 2026-10-29: blocked by a zone hold, certificate active. */
    public function testZoneHoldBlockedRechecksWithoutTheVerifyOwnershipPost(): void
    {
        $options = $this->useCacheDir();
        $domain = $this->domain(F::ZONE_HOLD_BLOCKED);
        $this->assertSame(1, $this->verify($domain->id, [$domain], [], $options));
        $out = $this->plain();
        $this->assertSame($this->patchOnly($domain->id), $this->callLog());
        $this->assertStringContainsString('Force recheck running: Cloudflare checks the domain again now. If you run this command again', $out);
        $this->assertStringContainsString('This domain\'s zone is in its owner\'s own Cloudflare account, and that zone has a zone hold.', $out);
        $this->assertStringContainsString('Pantheon support can\'t release it.', $out);
        $this->assertStringContainsString('open the domain\'s Overview page', $out);
        $this->assertStringContainsString('This run asked Cloudflare to check again. While the hold is on, the domain stays blocked.', $out);
        $this->assertStringContainsString('Don\'t point DNS at Pantheon until the domain is active.', $out);
        $this->assertStringContainsString('Ownership: The hostname is associated with a held zone.', $out);
        $this->assertStringNotContainsString('Contact Pantheon support', $out);
        $this->assertStringNotContainsString('Cloudflare has blocked this domain.', $out);
    }

    public function testZoneHoldBlockedWithNoRecheckPointsToTheRecheck(): void
    {
        $this->assertSame(1, $this->verifyFixture(F::ZONE_HOLD_BLOCKED, true, ['no-recheck' => true]));
        $out = $this->plain();
        $this->assertSame($this->readOnly(), $this->callLog());
        $this->assertStringContainsString('Then run: terminus gcdn:verify my-site.live held-blocked.example.com', $out);
        $this->assertStringNotContainsString('This run asked Cloudflare', $out);
    }

    public function testZoneHoldBlockedIgnoresMethod(): void
    {
        $this->verifyFixture(F::ZONE_HOLD_BLOCKED, true, ['method' => 'http']);
        $this->assertEquals((object) [], $this->writes()[0][2]['form_params'], 'a plain recheck, no method change');
    }

    public function testDcvComesFromTheTableWhenTheApiHasNone(): void
    {
        $this->verifyFixture(F::PENDING, false);
        $this->assertStringContainsString(' pending.example.com.dc5ecddca9c0e249.dcv.cloudflare.com. ', $this->plain());
    }

    public function testMalformedApiDcvFallsBackToTheTable(): void
    {
        $domain = $this->domain(F::PENDING);
        $domain->challenges->dcv_delegation->val = 'pending.example.com.evil.example.net';
        $this->verify($domain->id, [$domain]);
        $out = $this->plain();
        $this->assertStringContainsString(' pending.example.com.dc5ecddca9c0e249.dcv.cloudflare.com. ', $out);
        $this->assertStringNotContainsString('evil', $out);
    }

    public function testApiDcvWorksForZonesMissingFromTheTable(): void
    {
        $domain = $this->domain(F::CANARY_ZONE);
        $domain->challenges->dcv_delegation = (object) ['key' => '_acme-challenge.canary.example.com', 'val' => 'canary.example.com.0123456789abcdef.dcv.cloudflare.com'];
        $this->verify($domain->id, [$domain]);
        $this->assertStringContainsString(' canary.example.com.0123456789abcdef.dcv.cloudflare.com. ', $this->plain());
    }

    public function testUnknownZoneWithoutApiDcvSaysContactSupport(): void
    {
        $this->verifyFixture(F::UNKNOWN_ZONE);
        $out = $this->plain();
        $this->assertRow($out, 'Issue: Certificate record', 'Pantheon couldn\'t determine the certificate validation record for this domain.');
        $this->assertRow($out, 'Contact Pantheon support.');
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
        $this->assertStringContainsString('Force recheck skipped (--no-recheck).', $this->plain());
    }

    /** A recent poll only read the status; it doesn't replace the recheck. */
    public function testRecheckIsNotSkippedRightAfterACloudflareRead(): void
    {
        $domain = $this->domain(F::PENDING);
        $this->verify($domain->id, [$domain], [], [], fn($cmd) => $cmd->clock = TestVerifyCommand::OBSERVED_AT + 10);
        $this->assertSame($this->recheckAndPost($domain->id), $this->callLog());
        $this->assertStringNotContainsString('Force recheck skipped', $this->plain());
    }

    public function testRecheckCooldownOnThisMachine(): void
    {
        $config = $this->useCacheDir();
        $domain = $this->domain(F::PENDING);
        $start = TestVerifyCommand::OBSERVED_AT + 3600;

        $this->verify($domain->id, [$domain], [], $config, fn($cmd) => $cmd->clock = $start);
        $this->assertCount(2, $this->writes(), 'first run rechecks');
        $this->assertFileExists($this->cacheDir . '/gcdn-recheck.json');

        // Within the cooldown, the run is a --no-recheck one: no PATCH, no POST.
        $this->verify($domain->id, [$domain], [], $config, fn($cmd) => $cmd->clock = $start + 30);
        $this->assertSame($this->readOnly(), $this->callLog());
        $out = $this->plain();
        $this->assertStringContainsString('Showing the latest poll: a force recheck ran from this machine less than 5 minutes ago.', $out);
        $this->assertStringContainsString('A force recheck can be run in 4 minutes 30 seconds. To run one now anyway, use --recheck.', $out);
        $this->assertStringContainsString('Next: add the records in steps 1 and 2, then run:', $out);

        $this->verify('PENDING.example.com', [$domain], [], $config, fn($cmd) => $cmd->clock = $start + 30);
        $this->assertCount(0, $this->writes(), 'the cooldown ignores case');

        $this->verify($domain->id, [$domain], [], $config, fn($cmd) => $cmd->clock = $start + 240);
        $this->assertStringContainsString('A force recheck can be run in 1 minute. To run', $this->plain());

        $this->verify($domain->id, [$domain], [], $config, fn($cmd) => $cmd->clock = $start + 299);
        $this->assertCount(0, $this->writes(), 'still within five minutes');
        $this->assertStringContainsString('A force recheck can be run in 1 second. To run', $this->plain());

        $this->verify($domain->id, [$domain], [], $config, fn($cmd) => $cmd->clock = $start + 300);
        $this->assertCount(2, $this->writes(), 'rechecks again after five minutes');
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

    // The record check: a recheck only once there's something new to check.

    public function testNoRecheckBeforeTheTxtIsInDns(): void
    {
        $domain = $this->domain(F::PENDING);
        $this->assertSame(1, $this->verify($domain->id, [$domain], [], [], fn($cmd) => $cmd->records = [
            'TXT' => ['_cf-custom-hostname.pending.example.com' => []],
            'CNAME' => ['pending.example.com' => [], '_acme-challenge.pending.example.com' => []],
            'A' => ['pending.example.com' => ['172.67.1.1']],
        ]));
        $out = $this->plain();
        $this->assertSame($this->postOnly($domain->id), $this->callLog());
        $this->assertStringContainsString('Force recheck skipped: the step 1 TXT record isn\'t in DNS with the value shown yet, so there\'s nothing new to check. To force one anyway, use --recheck.', $out);
        $this->assertRow($out, 'TXT', '_cf-custom-hostname.pending.example.com', $domain->challenges->ownership_txt->val, 'Add this record: not in DNS yet.');
        $this->assertRow($out, 'CNAME', '_acme-challenge.pending.example.com', 'pending.example.com.dc5ecddca9c0e249.dcv.cloudflare.com.', 'Add this record: not in DNS yet.');
        $this->assertStringContainsString('Next: add the records in steps 1 and 2, then run: terminus gcdn:verify my-site.live pending.example.com', $out);
    }

    public function testRecheckOnceTheTxtIsInDns(): void
    {
        $domain = $this->domain(F::PENDING);
        $txt = $domain->challenges->ownership_txt;
        $this->verify($domain->id, [$domain], [], [], fn($cmd) => $cmd->records = ['TXT' => [$txt->key => [$txt->val]], 'CNAME' => [], 'A' => []]);
        $this->assertSame($this->recheckAndPost($domain->id), $this->callLog());
        $out = $this->plain();
        $this->assertRow($out, 'TXT', $txt->key, $txt->val, 'None: found in DNS.');
        $this->assertStringContainsString('1. Ownership (TXT record): in DNS, waiting for confirmation', $out);
        $this->assertStringContainsString('in the next 5 minutes, it shows the latest poll.', $out);
        $this->assertStringContainsString('Check with: terminus gcdn:verify my-site.live pending.example.com' . "\n", $out);
        $this->assertStringNotContainsString('--no-recheck', $out);
    }

    public function testRecheckOnceTrafficPointsHereEvenWithoutTheTxt(): void
    {
        $domain = $this->domain(F::PENDING);
        $this->verify($domain->id, [$domain], [], [], fn($cmd) => $cmd->records = [
            'TXT' => ['_cf-custom-hostname.pending.example.com' => []],
            'CNAME' => ['pending.example.com' => ['fe.cfp1c.edge.pantheon.io']],
            'A' => ['pending.example.com' => []],
        ]);
        $this->assertSame($this->recheckAndPost($domain->id), $this->callLog());
    }

    public function testForceRecheckSkipsTheRecordCheckAndTheCooldown(): void
    {
        $config = $this->useCacheDir();
        $domain = $this->domain(F::PENDING);
        $none = fn($cmd) => $cmd->records = [
            'TXT' => ['_cf-custom-hostname.pending.example.com' => []],
            'CNAME' => ['pending.example.com' => []],
            'A' => ['pending.example.com' => []],
        ];
        $this->verify($domain->id, [$domain], ['recheck' => true], $config, $none);
        $this->assertSame($this->recheckAndPost($domain->id), $this->callLog());
        $this->verify($domain->id, [$domain], ['recheck' => true], $config, $none);
        $this->assertCount(2, $this->writes(), 'no cooldown either');
    }

    public function testCertPendingChecksTheDcvCnameAndShowsAWrongValue(): void
    {
        $domain = $this->domain(F::PENDING_CERT);
        $dcv = '_acme-challenge.' . $domain->id;
        $this->verify($domain->id, [$domain], [], [], fn($cmd) => $cmd->records = ['CNAME' => [$dcv => ['ending-cert.example.com.dc5ecddca9c0e249.dcv.cloudflare.com']]]);
        $out = $this->plain();
        $this->assertSame($this->postOnly($domain->id), $this->callLog());
        $this->assertRow($out, 'CNAME', $dcv, $domain->challenges->dcv_delegation->val . '.', 'Change it to this value. DNS has: ending-cert.example.com.dc5ecddca9c0e249.dcv.cloudflare.com (another Cloudflare account\'s certificate validation, likely the current host)');
        $this->assertStringContainsString('Force recheck skipped: the step 2 CNAME isn\'t in DNS with the value shown yet', $out);
        $this->assertStringContainsString('1. Ownership (TXT record): done', $out);
        $this->assertStringContainsString('3. Switch traffic to Pantheon: not yet: after step 2', $out);
    }

    public function testAWrongOwnershipTxtIsNamedAsSuch(): void
    {
        $domain = $this->domain(F::PENDING);
        $txt = $domain->challenges->ownership_txt;
        $this->verify($domain->id, [$domain], ['no-recheck' => true], [], fn($cmd) => $cmd->records = ['TXT' => [$txt->key => ['93bf7320-old']], 'CNAME' => [], 'A' => []]);
        $out = $this->plain();
        $this->assertRow($out, 'TXT', $txt->key, $txt->val, 'Change it to this value. DNS has: 93bf7320-old');

        $this->verify($domain->id, [$domain], ['no-recheck' => true], [], fn($cmd) => $cmd->records = ['TXT' => [$txt->key => ['one', 'two']], 'CNAME' => [], 'A' => []]);
        $this->assertStringContainsString('Change it to this value. DNS has: one, two', $this->plain());
    }

    public function testAnOtherCnameAtTheDcvNameIsNamedAsSuch(): void
    {
        $domain = $this->domain(F::PENDING_CERT);
        $dcv = '_acme-challenge.' . $domain->id;
        $this->verify($domain->id, [$domain], ['no-recheck' => true], [], fn($cmd) => $cmd->records = ['CNAME' => [$dcv => ['acme.example.net']]]);
        $out = $this->plain();
        $this->assertRow($out, 'CNAME', $dcv, $domain->challenges->dcv_delegation->val . '.', 'Change it to this value. DNS has: acme.example.net');
        $this->assertStringNotContainsString('another Cloudflare account', $out);
    }

    public function testTxtRecordsAtTheDcvNameAreFlaggedOnlyWithoutACname(): void
    {
        $domain = $this->domain(F::PENDING_CERT);
        $dcv = '_acme-challenge.' . $domain->id;
        $this->verify($domain->id, [$domain], ['no-recheck' => true], [], fn($cmd) => $cmd->records = ['CNAME' => [$dcv => []], 'TXT' => [$dcv => ['old-token']]]);
        $out = $this->plain();
        $this->assertRow($out, 'CNAME', $dcv, $domain->challenges->dcv_delegation->val . '.', 'Delete the TXT records with this name, then add this record: they conflict with it.');

        $value = $domain->challenges->dcv_delegation->val;
        $this->verify($domain->id, [$domain], ['no-recheck' => true], [], fn($cmd) => $cmd->records = ['CNAME' => [$dcv => [$value]], 'TXT' => [$dcv => ['cloudflare-token']]]);
        $this->assertStringNotContainsString('Delete the TXT records with this name', $this->plain());
        $this->assertNotContains("TXT {$dcv}", $this->cmd->recordLookups, 'with a CNAME, the TXT lookup would read its target');
    }

    public function testReadyAndMovedAlwaysRecheck(): void
    {
        foreach ([F::READY, F::READY_MOVED] as $fixture) {
            $domain = $this->domain($fixture);
            $this->verify($domain->id, [$domain], [], [], fn($cmd) => $cmd->records = ['TXT' => [], 'CNAME' => [], 'A' => []]);
            $this->assertSame($this->recheckAndPost($domain->id), $this->callLog(), $fixture);
            $this->assertSame(['CNAME _acme-challenge.' . $domain->id], $this->cmd->recordLookups, 'only the DCV CNAME for ' . $fixture);
        }
    }

    public function testStepsAreRedUntilDoneAndStatesLightBlue(): void
    {
        $this->cmd = new TestVerifyCommand();
        $this->wire($this->cmd);
        $decorated = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        $this->cmd->setOutput($decorated);
        $this->domainsList($this->domain(F::PENDING_CERT));
        $this->on('PATCH', self::PATCH_PATH, $this->apiResult(200, 'ok'));
        $this->on('POST', self::VERIFY_PATH, $this->apiResult(200, (object) []));
        $this->cmd->verify('my-site.live', 'cert.example.com', ['method' => null, 'no-recheck' => true]);
        $out = $decorated->fetch();
        $this->assertStringContainsString("Cloudflare: \033[94mownership verified, certificate pending\033[0m", $out);
        $this->assertStringContainsString("hostname \033[32mactive\033[0m, certificate \033[33mpending_validation\033[0m", $out);
        // Each table's title is in its status color.
        $this->assertStringContainsString("\033[32m\033[1m1. Ownership (TXT record): done\033[0m", $out);
        $this->assertStringContainsString("\033[31m\033[1m2. Certificate (DCV CNAME): not done", $out);
        $this->assertStringContainsString("\033[94mOption 1: CNAME\033[0m", $out, 'options in light blue');
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
        $this->assertRow($out, 'Issue: Certificate', 'The certificate authority could not validate');
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
        $this->assertRow($out, 'Issue: Ownership record', 'The TXT record isn\'t available yet.');
        $this->assertRow($out, 'Try again shortly.');
    }
}

<?php

namespace Pantheon\TerminusGCDN\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Request\RequestAwareTrait;
use Pantheon\TerminusGCDN\ChallengeState;

/**
 * Class VerifyCommand.
 *
 * Rechecks a GCDN (Cloudflare) domain and shows its status and the DNS
 * records it needs.
 *
 * The recheck is the dashboard's Force Recheck: a hostname PATCH that makes
 * policy-docs resend the hostname to Cloudflare and poll it again. Nothing
 * on the server limits it, and each one is a Cloudflare API call on a shared
 * account budget, so this command limits it (see RECHECK_COOLDOWN).
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class VerifyCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
    use SiteAwareTrait;
    use RequestAwareTrait;
    use DomainOutputTrait;

    /**
     * Seconds before this machine rechecks the same hostname again. Pantheon
     * takes up to about 10 minutes to see a recheck's result, so a second one
     * sooner adds nothing. A run within it shows the latest poll and when a
     * force recheck can run, with no writes, as with --no-recheck.
     */
    const RECHECK_COOLDOWN = 300;

    const RECHECK_CACHE_FILE = 'gcdn-recheck.json';

    const METHOD_TO_API = ['dns' => 'txt', 'http' => 'http'];

    /** --cert-txt: step 2 is the raw `_acme-challenge` TXT, not the DCV CNAME. */
    private bool $certTxt = false;

    /**
     * Rechecks a GCDN domain and shows its status and DNS records.
     *
     * For a GCDN custom domain that isn't active yet, this asks Cloudflare to
     * check it again now (like Force Recheck in the dashboard). Pantheon sees
     * the result within about 10 minutes; run the command again to see it.
     * A domain is rechecked at most every 5 minutes from this machine (a run
     * sooner shows the latest poll, as with --no-recheck), and only once a
     * record Cloudflare checks is in DNS (--recheck forces it).
     *
     * Exits 0 when the domain is active or its DNS records are ready, and 1
     * when it isn't ready, isn't found, or its status is unavailable.
     *
     * @authorize
     *
     * @command gcdn:verify
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $domain Domain e.g. `example.com`
     * @option method Switch certificate validation to dns or http as part of the recheck
     * @option no-recheck Only show the status: no recheck and no other changes
     * @option recheck Recheck even within the cooldown or before the records are in DNS
     * @option cert-txt Show the _acme-challenge TXT record for step 2 instead of the DCV CNAME, for DNS that can't take the CNAME
     *
     * @usage <site>.<env> <domain_name> Rechecks <domain_name> and shows its status and DNS records.
     * @usage <site>.<env> <domain_name> --no-recheck Shows the status without a recheck.
     * @usage <site>.<env> <domain_name> --method=http Switches to HTTP validation and rechecks.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function verify($site_env, $domain, $options = ['method' => null, 'no-recheck' => false, 'recheck' => false, 'cert-txt' => false])
    {
        $env = $this->getEnv($site_env);
        $site = $this->getSiteById($site_env);
        $siteEnv = $site->getName() . '.' . $env->getName();
        $this->certTxt = !empty($options['cert-txt']);

        $apiMethod = null;
        if (!empty($options['method'])) {
            $method = strtolower((string) $options['method']);
            if (!isset(self::METHOD_TO_API[$method])) {
                throw new TerminusException('Invalid method "{method}". Use "dns" or "http".', ['method' => $method]);
            }
            $apiMethod = self::METHOD_TO_API[$method];
        }

        $this->line('');
        $this->line($this->paint('=== ' . $site->getName() . ' site on ' . $env->getName() . ' environment ===', 'cyan', 'bold'));
        $this->line($this->paint($domain, 'yellow'));
        $this->line('------');

        $domains = $this->fetchDomains($site, $env);
        if ($domains === null) {
            $this->line($this->paint('Could not fetch the domain status. Try again shortly.', 'red'));
            $this->line('');
            return 1;
        }

        $domainInfo = $this->findDomain($domains, $domain);
        if ($domainInfo === null) {
            $this->line($this->paint("Domain not found on {$siteEnv}. Add it first:", 'red'));
            $this->line("  terminus domain:add {$siteEnv} {$domain}");
            $this->line('');
            return 1;
        }

        $state = new ChallengeState($domainInfo);
        $domain = $state->hostname();
        $this->line('Type: ' . ($domainInfo->type ?? 'custom'));
        $this->line('');

        if (!$state->isCustomCloudflare() || $state->state() === ChallengeState::MISSING) {
            if ($apiMethod !== null) {
                $this->log()->notice('--method only applies to GCDN custom domains; ignored.');
            }
            return $this->renderNonCloudflare($state, $siteEnv);
        }

        // Writes, for GCDN custom domains only (P1): the recheck, then the
        // verify-ownership POST that lets titan complete an active domain.
        // --no-recheck makes no writes at all, and neither does a status a
        // recheck can't fix (blocked, being deleted, ...). A zone hold is the
        // exception: once the owner releases it, the recheck is the fix.
        $found = $this->lookUpStepRecords($state);
        $rechecked = false;
        if (!empty($options['no-recheck'])) {
            $this->line('Force recheck skipped (--no-recheck).' . ($apiMethod !== null ? ' --method was not applied.' : ''));
        } elseif ($state->actionRequired() && !$state->zoneHold()) {
            $this->line("Force recheck skipped: a recheck can't fix Cloudflare status \"{$state->cloudflareStatus()}\".");
        } elseif ($state->actionRequired()) {
            if ($apiMethod !== null) {
                $this->log()->notice('--method doesn\'t apply while a zone hold blocks the domain; ignored.');
            }
            $rechecked = (bool) $this->recheck($site, $env, $state, null, $found, !empty($options['recheck']));
        } else {
            $rechecked = $this->recheck($site, $env, $state, $apiMethod, $found, !empty($options['recheck']));
            // null: within the cooldown, so this run is a --no-recheck one.
            if ($rechecked !== null) {
                $this->notifyVerifyOwnership($site, $env, $domain);
            }
            $rechecked = (bool) $rechecked;
        }
        // An active domain gets no recheck line, so no gap either.
        if (!$state->active() || !empty($options['no-recheck']) || $apiMethod !== null) {
            $this->line('');
        }

        return $this->renderCloudflare($state, $siteEnv, $rechecked, $found);
    }

    /**
     * @return array|null The domains, or null when the request failed.
     */
    private function fetchDomains($site, $env): ?array
    {
        $url = sprintf(
            'sites/%s/environments/%s/domains?hydrate[]=as_list&hydrate[]=recommendations',
            $site->id,
            $env->id
        );
        try {
            $response = $this->request()->request($url, ['method' => 'get']);
        } catch (TerminusException $e) {
            $this->log()->warning('Could not fetch domains: {msg}', ['msg' => $e->getMessage()]);
            return null;
        }
        if ($response->isError()) {
            return null;
        }
        $data = $response->getData();
        return is_array($data) ? $data : [];
    }

    private function renderNonCloudflare(ChallengeState $state, string $siteEnv): int
    {
        switch ($state->state()) {
            case ChallengeState::UNAVAILABLE:
                $this->line($this->paint('Status unavailable: Pantheon couldn\'t read this domain\'s CDN status. Try again shortly.', 'red'));
                $this->line('');
                return 1;

            case ChallengeState::PLATFORM:
                $this->line('Managed by Pantheon: platform domains need no DNS records or verification.');
                $this->line('');
                return 0;

            case ChallengeState::FASTLY:
                $this->line('This domain is not on GCDN (Cloudflare). Its DNS records:');
                $this->writeTrafficRecords($state, '  ');
                $this->line('');
                $this->line("Run \"terminus domain:dns {$siteEnv}\" for every domain on the environment.");
                $this->line('');
                return 0;

            default:
                $this->line($this->paint('Status not available yet: Pantheon hasn\'t received this domain\'s Cloudflare status. Try again shortly.', 'yellow'));
                $this->line('');
                return 1;
        }
    }

    private function renderCloudflare(ChallengeState $state, string $siteEnv, bool $rechecked, array $found): int
    {
        if ($state->state() === ChallengeState::ACTION_REQUIRED) {
            $this->writeActionRequired($state, $siteEnv, $rechecked);
            $this->line('');
            return 1;
        }

        $this->writeHeadline($state);
        $this->writeCloudflareStatus($state);
        $this->writePaused($state, $siteEnv, $rechecked);
        $this->writeZoneHold($state);
        if ($state->state() === ChallengeState::CANDIDATE) {
            $this->line('This domain is served through Cloudflare today (your own Cloudflare account or your current');
            $this->line('host), so Pantheon confirms ownership through the step 1 TXT record.');
        }
        if ($state->state() === ChallengeState::READY) {
            $this->writeConfirmedDespiteErrors($state, 'See "Issues and Notes" in step 1.');
        }
        $this->line('');
        // Cloudflare's errors, what can be done and the cutover run are in the step tables.
        $this->writeSteps($state, '', $found, $this->certTxt, $siteEnv);
        $this->line('');
        if ($state->state() !== ChallengeState::READY) {
            $this->writeNext($state, $siteEnv, $rechecked);
            if ($state->state() === ChallengeState::CANDIDATE) {
                $this->line("For the full Cloudflare-to-Cloudflare steps, run \"terminus gcdn:o2o {$siteEnv} {$state->hostname()}\".");
            } elseif ($state->state() === ChallengeState::PENDING && !$state->ownershipDoneCertPending()) {
                $this->line("Domain proxied through your own Cloudflare account? Run \"terminus gcdn:o2o {$siteEnv} {$state->hostname()}\".");
            }
            $this->line('');
        }
        return in_array($state->state(), [ChallengeState::ACTIVE, ChallengeState::READY], true) ? 0 : 1;
    }

    /**
     * Sends the Force Recheck PATCH (`{}`, or `{verify_method}`) unless the
     * domain is active or a recheck just happened. A method change isn't
     * held back by the cooldown.
     *
     * @param array $found The step records in DNS (lookUpStepRecords()).
     * @param bool $force --recheck: skip the cooldown and the record check.
     *
     * @return bool|null Whether this run asked Cloudflare to check again, or
     *   null within the cooldown: no writes at all, as with --no-recheck.
     */
    private function recheck($site, $env, ChallengeState $state, ?string $apiMethod, array $found, bool $force): ?bool
    {
        if ($state->active()) {
            if ($apiMethod !== null) {
                $this->line('The domain is already active; the validation method was not changed.');
            }
            return false;
        }

        $currentMethod = $state->verifyMethod();
        $body = (object) [];
        if ($apiMethod !== null && $apiMethod !== $currentMethod) {
            if ($apiMethod === 'http' && ($state->candidate() || $state->ready())) {
                // P15: the customer's Cloudflare proxy intercepts HTTP validation.
                $this->line($this->paint(
                    'HTTP validation can\'t pass while this domain is served through Cloudflare; keeping DNS validation.',
                    'yellow'
                ));
            } else {
                $body = (object) ['verify_method' => $apiMethod];
            }
        }

        $hostname = $state->hostname();
        $isMethodChange = isset($body->verify_method);
        if (!$isMethodChange && !$force) {
            $missing = $this->nothingToCheck($state, $found);
            if ($missing !== null) {
                $this->line("Force recheck skipped: {$missing}, so there's nothing new to check. To force one anyway, use --recheck.");
                return false;
            }
            $wait = $this->recheckWait($site, $env, $state);
            if ($wait !== null) {
                $this->line('Showing the latest poll: a force recheck ran from this machine less than 5 minutes ago.');
                $this->line('A force recheck can be run in ' . $this->duration($wait) . '. To run one now anyway, use --recheck.');
                return null;
            }
        }

        $url = sprintf('sites/%s/environments/%s/hostnames/%s', $site->id, $env->id, rawurlencode($hostname));
        try {
            $response = $this->request()->request($url, ['method' => 'PATCH', 'form_params' => $body]);
            $failed = $response->isError() ? 'HTTP ' . $response->getStatusCode() : null;
        } catch (TerminusException $e) {
            $failed = $e->getMessage();
        }

        if ($failed !== null) {
            $this->log()->warning('Recheck request failed ({reason}). Showing the current status.', ['reason' => $failed]);
            return false;
        }

        $this->rememberRecheck($site, $env, $hostname);
        if ($isMethodChange) {
            $this->line('Validation method switched to ' . ($body->verify_method === 'http' ? 'HTTP' : 'DNS') . '.');
        }
        $this->line('Force recheck running: Cloudflare checks the domain again now. If you run this command again');
        $this->line('in the next 5 minutes, it shows the latest poll.');
        return true;
    }

    /**
     * Why a `{}` recheck can't help yet, or null when it can.
     *
     * Cloudflare (or Pantheon, for a domain served through Cloudflare) only
     * checks records, so a recheck before any of them is in DNS only restarts
     * policy-docs' polling: about 140 Cloudflare calls over two hours, from
     * an account-wide budget. A failed lookup counts as in place.
     */
    private function nothingToCheck(ChallengeState $state, array $found): ?string
    {
        // moved or deleted: the recheck is what restores it. Ready: the
        // switch happens behind the customer's own Cloudflare proxy, where
        // DNS can't see it. Zone hold: releasing it changes nothing in DNS.
        if ($state->paused() || $state->ready() || $state->zoneHold()) {
            return null;
        }
        if ($state->ownershipDoneCertPending() && $this->certTxt) {
            return $this->inDns($found['dcvTxt'] ?? null, $state->certTxt()['value'] ?? null) === false
                ? 'the step 2 TXT record isn\'t in DNS with the value shown yet'
                : null;
        }
        if ($state->ownershipDoneCertPending()) {
            return $this->inDns($found['dcv'] ?? null, $state->dcv()['value'] ?? null) === false
                ? 'the step 2 CNAME isn\'t in DNS with the value shown yet'
                : null;
        }
        if ($this->inDns($found['txt'] ?? null, $state->ownershipTxt()['value'] ?? null) !== false) {
            return null;
        }
        if (!$state->candidate() && $this->trafficInDns($state) !== false) {
            return null;
        }
        return 'the step 1 TXT record isn\'t in DNS with the value shown yet';
    }

    /**
     * The step 1 TXT and step 2 CNAME as DNS has them, while those steps
     * aren't done: ['txt' => string[]|null, 'dcv' => string[]|null,
     * 'dcvTxt' => string[]|null]. dcvTxt: TXT records at the DCV name,
     * which conflict with the CNAME; looked up only when there's no CNAME.
     * Once steps 1 and 2 are done, only 'dcv'.
     */
    private function lookUpStepRecords(ChallengeState $state): array
    {
        if (!$state->isCustomCloudflare() || $state->actionRequired()) {
            return [];
        }
        // Done (active or ready): only the DCV CNAME, for the renewal advice.
        // A CNAME lookup returns quickly even for a missing name.
        if ($state->active() || $state->ready()) {
            $dcv = $state->dcv();
            return $dcv !== null ? ['dcv' => $this->lookupRecords($dcv['name'], 'CNAME')] : [];
        }
        $found = [];
        $txt = $state->ownershipTxt();
        if ($txt !== null && !$state->ownershipDoneCertPending()) {
            $found['txt'] = $this->lookupRecords($txt['name'], 'TXT');
        }
        $dcv = $state->dcv();
        if ($dcv !== null) {
            $found['dcv'] = $this->lookupRecords($dcv['name'], 'CNAME');
            if ($found['dcv'] === []) {
                $found['dcvTxt'] = $this->lookupRecords($dcv['name'], 'TXT');
            }
        }
        return $found;
    }

    /** Whether DNS has $expected, or null when unknown. */
    private function inDns(?array $values, ?string $expected): ?bool
    {
        if ($values === null || $expected === null) {
            return null;
        }
        $normalize = fn(string $v) => strtolower(rtrim(trim($v, " \"\t"), '.'));
        return in_array($normalize($expected), array_map($normalize, $values), true);
    }

    /** Whether the domain already points to Pantheon, or null when unknown. */
    private function trafficInDns(ChallengeState $state): ?bool
    {
        $target = $state->trafficTarget();
        $cnames = $this->lookupRecords($state->hostname(), 'CNAME');
        if ($target !== null && $this->inDns($cnames, $target)) {
            return true;
        }
        $expected = array_column(array_filter($state->dnsRecords(), fn($r) => $r['type'] === 'A'), 'value');
        $addresses = $this->lookupRecords($state->hostname(), 'A');
        if ($cnames === null || $addresses === null) {
            return null;
        }
        return array_intersect($expected, $addresses) !== [];
    }

    /**
     * Seconds until a `{}` recheck may run again, or null when it may now.
     *
     * Only this machine's own last recheck counts. A recent Cloudflare status
     * read (`cloudflare_status_observed_at`) doesn't: a poll only reads the
     * status, while the PATCH makes Cloudflare check the domain again, and
     * every PATCH restarts policy-docs' 10-30 second polling, so that time is
     * nearly always recent right after a domain is added or rechecked.
     */
    private function recheckWait($site, $env, ChallengeState $state): ?int
    {
        $now = $this->now();
        $last = $this->readRecheckCache()[$this->recheckKey($site, $env, $state->hostname())] ?? null;
        if (is_int($last) && $now - $last >= 0 && $now - $last < self::RECHECK_COOLDOWN) {
            return self::RECHECK_COOLDOWN - ($now - $last);
        }
        return null;
    }

    /** "4 minutes 30 seconds", "1 minute", "45 seconds". */
    private function duration(int $seconds): string
    {
        $parts = [];
        foreach ([[intdiv($seconds, 60), 'minute'], [$seconds % 60, 'second']] as [$count, $unit]) {
            if ($count > 0) {
                $parts[] = $count . ' ' . $unit . ($count === 1 ? '' : 's');
            }
        }
        return implode(' ', $parts);
    }

    /**
     * The verify-ownership POST. Titan reads policy-docs and, only when
     * Cloudflare has activated the hostname, completes it (titan's own
     * record and the policy doc). Its errors never hide the status (P4).
     */
    private function notifyVerifyOwnership($site, $env, string $hostname): void
    {
        $url = sprintf(
            'sites/%s/environments/%s/hostnames/%s/verify-ownership',
            $site->id,
            $env->id,
            rawurlencode($hostname)
        );
        try {
            $response = $this->request()->request($url, ['method' => 'POST']);
            if ($response->isError()) {
                $this->log()->warning('Could not notify Pantheon (HTTP {code}). Showing the current status.', ['code' => $response->getStatusCode()]);
            }
        } catch (TerminusException $e) {
            $this->log()->warning('Could not notify Pantheon ({msg}). Showing the current status.', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * A seam for tests: the values of a hostname's TXT, CNAME or A records
     * ([] when there are none), or null when the lookup failed.
     *
     * @return string[]|null
     */
    protected function lookupRecords(string $host, string $type): ?array
    {
        $types = ['TXT' => DNS_TXT, 'CNAME' => DNS_CNAME, 'A' => DNS_A];
        $keys = ['TXT' => 'txt', 'CNAME' => 'target', 'A' => 'ip'];
        $records = @dns_get_record($host, $types[$type]);
        if (!is_array($records)) {
            return null;
        }
        return array_values(array_filter(array_map(fn($r) => (string) ($r[$keys[$type]] ?? ''), $records), fn($v) => $v !== ''));
    }

    /** A seam for tests. */
    protected function now(): int
    {
        return time();
    }

    private function recheckKey($site, $env, string $hostname): string
    {
        return $site->id . '/' . $env->id . '/' . strtolower($hostname);
    }

    private function recheckCachePath(): ?string
    {
        $dir = $this->getConfig() ? $this->getConfig()->get('cache_dir') : null;
        return is_string($dir) && $dir !== '' ? $dir . DIRECTORY_SEPARATOR . self::RECHECK_CACHE_FILE : null;
    }

    private function readRecheckCache(): array
    {
        $path = $this->recheckCachePath();
        if ($path === null || !is_readable($path)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Records the recheck time. Best effort: a failed write only loses the
     * cooldown. Entries older than the cooldown are dropped.
     */
    private function rememberRecheck($site, $env, string $hostname): void
    {
        $path = $this->recheckCachePath();
        if ($path === null) {
            return;
        }
        $now = $this->now();
        $cache = array_filter(
            $this->readRecheckCache(),
            fn($time) => is_int($time) && $now - $time < self::RECHECK_COOLDOWN
        );
        $cache[$this->recheckKey($site, $env, $hostname)] = $now;
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0700, true);
        }
        @file_put_contents($path, json_encode($cache), LOCK_EX);
    }
}

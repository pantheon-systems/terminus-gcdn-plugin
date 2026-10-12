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

    /** Seconds before this machine rechecks the same hostname again. */
    const RECHECK_COOLDOWN = 300;

    /** Seconds after a Cloudflare status read in which a recheck adds nothing. */
    const OBSERVED_COOLDOWN = 120;

    const RECHECK_CACHE_FILE = 'gcdn-recheck.json';

    const METHOD_TO_API = ['dns' => 'txt', 'http' => 'http'];

    /**
     * Rechecks a GCDN domain and shows its status and DNS records.
     *
     * For a GCDN custom domain that isn't active yet, this asks Cloudflare to
     * check it again now (like Force Recheck in the dashboard). Pantheon sees
     * the result within about 10 minutes; run the command again to see it.
     * A domain is rechecked at most once every 5 minutes from this machine.
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
     *
     * @usage <site>.<env> <domain_name> Rechecks <domain_name> and shows its status and DNS records.
     * @usage <site>.<env> <domain_name> --no-recheck Shows the status without a recheck.
     * @usage <site>.<env> <domain_name> --method=http Switches to HTTP validation and rechecks.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function verify($site_env, $domain, $options = ['method' => null, 'no-recheck' => false])
    {
        $env = $this->getEnv($site_env);
        $site = $this->getSiteById($site_env);
        $siteEnv = $site->getName() . '.' . $env->getName();

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
        // --no-recheck makes no writes at all.
        if (!empty($options['no-recheck'])) {
            $this->line('Recheck skipped (--no-recheck).' . ($apiMethod !== null ? ' --method was not applied.' : ''));
        } else {
            $this->recheck($site, $env, $state, $apiMethod);
            $this->notifyVerifyOwnership($site, $env, $domain);
        }
        $this->line('');

        return $this->renderCloudflare($state, $siteEnv, $domains);
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

    private function renderCloudflare(ChallengeState $state, string $siteEnv, array $domains): int
    {
        $domain = $state->hostname();

        switch ($state->state()) {
            case ChallengeState::ACTIVE:
                $this->line('Cloudflare: ' . $this->paint('ownership and certificate active', 'green'));
                $this->line('DNS: ' . ($state->dnsConfigured()
                    ? $this->paint('pointed to Pantheon', 'green')
                    : $this->paint('not pointed to Pantheon yet', 'yellow')));
                $this->line('');
                $this->writeTrafficRecords($state);
                $this->line('');
                $this->writeDcv($state);
                $this->line('');
                return 0;

            case ChallengeState::READY:
                $this->line('Cloudflare: ' . $this->paint('DNS records ready (confirmed by Pantheon)', 'green'));
                $this->writeCloudflareStatus($state);
                $this->writeZoneHold($state);
                $this->line('');
                $this->writeOwnershipTxt($state, 'keep it until the domain is active');
                $this->line('');
                $this->writeDcv($state);
                $this->line('');
                $this->writeTrafficRecords($state);
                $this->line('');
                $this->writeCutoverMessage($state, $siteEnv);
                $this->writeWwwWarning($domain, $domains);
                $this->line('');
                return 0;

            case ChallengeState::CANDIDATE:
                $this->line('Cloudflare: ' . $this->paint('ownership not confirmed yet', 'red'));
                $this->writeCloudflareStatus($state);
                $this->writeZoneHold($state);
                $this->line('This domain is served through Cloudflare today (your own Cloudflare account or your current');
                $this->line('host), so Pantheon confirms ownership through the TXT record below. Add it and the CNAME,');
                $this->line('then run this command again.');
                $this->line('');
                $this->writeOwnershipTxt($state, 'add this');
                $this->line('');
                $this->writeDcv($state);
                $this->line('');
                $this->line($this->paint('Don\'t switch DNS yet: Pantheon hasn\'t confirmed ownership. These are the records to switch to later:', 'yellow'));
                $this->writeTrafficRecords($state);
                $this->line('');
                $this->writeErrors($state);
                $this->line("For the full Cloudflare-to-Cloudflare steps, run \"terminus gcdn:o2o {$siteEnv} {$domain}\".");
                $this->line('');
                return 1;

            default:
                if ($state->ownershipDoneCertPending()) {
                    $this->line('Cloudflare: ' . $this->paint('ownership verified, certificate pending', 'yellow'));
                } else {
                    $this->line('Cloudflare: ' . $this->paint('ownership and certificate pending', 'red'));
                }
                $this->writeCloudflareStatus($state);
                $this->writeZoneHold($state);
                $this->line('');
                if (!$state->ownershipDoneCertPending()) {
                    $this->writeOwnershipTxt($state, 'add this');
                    $this->line('');
                }
                $this->writeDcv($state);
                $this->line('');
                $this->line($this->paint('Not ready yet: add the records above, then run this command again. These are the records to switch DNS to:', 'yellow'));
                $this->writeTrafficRecords($state);
                $this->line('');
                $this->writeErrors($state);
                $this->line("Domain proxied through your own Cloudflare account? Run \"terminus gcdn:o2o {$siteEnv} {$domain}\".");
                $this->line('');
                return 1;
        }
    }

    /**
     * Sends the Force Recheck PATCH (`{}`, or `{verify_method}`) unless the
     * domain is active or a recheck just happened. A method change isn't
     * held back by the cooldown.
     */
    private function recheck($site, $env, ChallengeState $state, ?string $apiMethod): void
    {
        if ($state->active()) {
            if ($apiMethod !== null) {
                $this->line('The domain is already active; the validation method was not changed.');
            }
            return;
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
        if (!$isMethodChange) {
            $skip = $this->recheckSkipReason($site, $env, $state);
            if ($skip !== null) {
                $this->line("Recheck skipped: {$skip}. Showing the current status.");
                return;
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
            return;
        }

        $this->rememberRecheck($site, $env, $hostname);
        if ($isMethodChange) {
            $this->line('Validation method switched to ' . ($body->verify_method === 'http' ? 'HTTP' : 'DNS') . '.');
        }
        $this->line('Recheck requested: Cloudflare checks the domain again now, and Pantheon sees the result');
        $this->line('within about 10 minutes. Run this command again to see it.');
    }

    /**
     * Why a `{}` recheck would add nothing right now, or null.
     */
    private function recheckSkipReason($site, $env, ChallengeState $state): ?string
    {
        $now = $this->now();

        $observed = $state->observedAt();
        $observedAt = $observed !== null ? strtotime($observed) : false;
        if ($observedAt !== false && $now - $observedAt >= 0 && $now - $observedAt < self::OBSERVED_COOLDOWN) {
            return sprintf('Pantheon read the Cloudflare status %d seconds ago', $now - $observedAt);
        }

        $last = $this->readRecheckCache()[$this->recheckKey($site, $env, $state->hostname())] ?? null;
        if (is_int($last) && $now - $last >= 0 && $now - $last < self::RECHECK_COOLDOWN) {
            $minutes = intdiv($now - $last, 60);
            return $minutes < 1
                ? 'one was requested from this machine less than a minute ago'
                : sprintf('one was requested from this machine %d minute%s ago', $minutes, $minutes === 1 ? '' : 's');
        }

        return null;
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
     * Warns when www.<domain> follows the apex (a CNAME to it, or the same
     * addresses) but isn't a domain on this environment: switching the apex
     * would send www to Pantheon too.
     */
    private function writeWwwWarning(string $domain, array $domains): void
    {
        if (stripos($domain, 'www.') === 0 || strpos($domain, '*') !== false) {
            return;
        }
        $www = 'www.' . $domain;
        if ($this->findDomain($domains, $www) !== null) {
            return;
        }

        $cname = $this->lookupCname($www);
        $follows = $cname !== null && strcasecmp(rtrim($cname, '.'), $domain) === 0;
        if (!$follows) {
            $wwwAddresses = $this->lookupAddresses($www);
            $follows = $wwwAddresses !== [] && $wwwAddresses === $this->lookupAddresses($domain);
        }
        if ($follows) {
            $this->line('');
            $this->line($this->paint(
                "www.{$domain} follows your root domain, so it moves to Pantheon when you switch DNS. Add www.{$domain} to this site too.",
                'yellow'
            ));
        }
    }

    /** A seam for tests: the CNAME target of a hostname, or null. */
    protected function lookupCname(string $host): ?string
    {
        $records = @dns_get_record($host, DNS_CNAME);
        return is_array($records) && isset($records[0]['target']) ? (string) $records[0]['target'] : null;
    }

    /**
     * A seam for tests: a hostname's sorted A and AAAA addresses.
     *
     * @return string[]
     */
    protected function lookupAddresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }
        $addresses = array_filter(array_map(fn($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records));
        sort($addresses);
        return array_values($addresses);
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

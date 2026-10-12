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
 * Class ChallengeCommand.
 *
 * Shows or toggles the certificate challenge method (DNS or HTTP) for
 * domains on a Cloudflare-migrated site. A toggle is a hostname PATCH that
 * makes policy-docs resend the hostname to Cloudflare, so it's refused for
 * domains that are active or whose DNS records are already ready.
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class ChallengeCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
    use SiteAwareTrait;
    use RequestAwareTrait;
    use DomainOutputTrait;

    const VALID_METHODS = ['dns', 'http'];
    const METHOD_TO_API = ['dns' => 'txt', 'http' => 'http'];

    const SKIP_REASONS = [
        'verified' => 'skipped (active)',
        'ready' => 'skipped (DNS records ready)',
        'candidate_http' => 'skipped (served through Cloudflare; HTTP validation would be intercepted)',
        'same_method' => null, // "skipped (already DNS|HTTP)"
    ];

    /**
     * Shows or toggles the certificate challenge method for a domain.
     *
     * Without --method, displays the current challenge method and records.
     * With --method, switches the method for domains that aren't active or
     * ready yet. HTTP is refused for domains served through Cloudflare today,
     * because the proxy intercepts HTTP validation.
     *
     * Use --all to apply to every Cloudflare custom domain on the environment.
     * Exits 1 when a toggle fails or the domain's status is unavailable.
     *
     * Note: A site converge resets the method to the hostname-type default
     * (custom → dns, platform → http).
     *
     * @authorize
     *
     * @command gcdn:challenge
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string|null $domain Domain e.g. `example.com` (omit when using --all)
     * @option method Set the challenge method: dns or http
     * @option all Apply to all Cloudflare custom domains on the environment (requires --method)
     *
     * @usage <site>.<env> <domain> Shows the current challenge method and records for <domain>.
     * @usage <site>.<env> <domain> --method=http Switches <domain> to HTTP certificate challenges.
     * @usage <site>.<env> --all --method=http Switches all eligible Cloudflare domains to HTTP.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function challenge($site_env, $domain = null, $options = ['method' => null, 'all' => false])
    {
        $env = $this->getEnv($site_env);
        $site = $this->getSiteById($site_env);
        $method = $options['method'];
        $all = !empty($options['all']);

        if ($method !== null) {
            $method = strtolower($method);
            if (!in_array($method, self::VALID_METHODS)) {
                throw new TerminusException(
                    'Invalid method "{method}". Use "dns" or "http".',
                    ['method' => $method]
                );
            }
        }

        if ($all && $method === null) {
            throw new TerminusException('--all requires --method. Example: --all --method=http');
        }

        if (!$all && $domain === null) {
            throw new TerminusException('Provide a domain name, or use --all to target all Cloudflare domains.');
        }

        $domainsData = $this->fetchDomains($site, $env);
        if ($domainsData === null) {
            throw new TerminusException(
                'Failed to fetch domains for {site}.{env}.',
                ['site' => $site->getName(), 'env' => $env->getName()]
            );
        }

        if ($all) {
            return $this->handleAll($site, $env, $domainsData, $method);
        }
        return $this->handleSingle($site, $env, $domainsData, $domain, $method);
    }

    private function fetchDomains($site, $env): ?array
    {
        $domainsUrl = sprintf(
            'sites/%s/environments/%s/domains?hydrate[]=as_list&hydrate[]=recommendations',
            $site->id,
            $env->id
        );
        $response = $this->request()->request($domainsUrl, ['method' => 'get']);
        if ($response->isError()) {
            return null;
        }
        $data = $response->getData();
        return is_array($data) ? $data : [];
    }

    private function handleSingle($site, $env, array $domainsData, string $domain, ?string $method): int
    {
        $domainInfo = $this->findDomain($domainsData, $domain);

        if ($domainInfo === null) {
            throw new TerminusException(
                'Domain {domain} not found on {site}.{env}.',
                ['domain' => $domain, 'site' => $site->getName(), 'env' => $env->getName()]
            );
        }

        $state = new ChallengeState($domainInfo);
        switch ($state->state()) {
            case ChallengeState::UNAVAILABLE:
                $this->line($this->paint('Status unavailable: Pantheon couldn\'t read this domain\'s CDN status. Try again shortly.', 'red'));
                return 1;
            case ChallengeState::PLATFORM:
                // P7: platform domains are Pantheon's to manage.
                $this->line('Managed by Pantheon: platform domains need no challenge records, and their method can\'t be changed.');
                return $method !== null ? 1 : 0;
            case ChallengeState::FASTLY:
                $this->line($this->paint(
                    'This domain is not on Cloudflare. Challenge method toggle is only available for GCDN-migrated domains.',
                    'red'
                ));
                return $method !== null ? 1 : 0;
            case ChallengeState::MISSING:
                $this->line($this->paint('Status not available yet: Pantheon hasn\'t received this domain\'s Cloudflare status. Try again shortly.', 'yellow'));
                return 1;
        }

        $toggled = false;
        if ($method !== null) {
            $result = $this->toggleMethod($site, $env, $state, $method);
            if ($result === null) {
                return 1;
            }
            $toggled = $result === true;
            if ($toggled) {
                // P6: show what the API has after the PATCH, not before it.
                $fresh = $this->fetchDomains($site, $env);
                $freshInfo = $fresh !== null ? $this->findDomain($fresh, $state->hostname()) : null;
                if ($freshInfo !== null) {
                    $state = new ChallengeState($freshInfo);
                }
            }
        }

        $this->renderChallengeInfo($state, $toggled, $toggled ? self::METHOD_TO_API[$method] : null);
        return 0;
    }

    private function handleAll($site, $env, array $domainsData, string $method): int
    {
        $eligible = [];
        foreach ($domainsData as $d) {
            if (!is_object($d) || empty($d->id)) {
                continue;
            }
            $state = new ChallengeState($d);
            if ($state->isCustomCloudflare() && $state->state() !== ChallengeState::MISSING) {
                $eligible[] = $state;
            }
        }

        if (empty($eligible)) {
            $this->line('No Cloudflare custom domains found on ' . $site->getName() . '.' . $env->getName());
            return 0;
        }

        $this->line('');
        $this->line($this->paint(
            '=== Bulk Challenge Method Toggle — ' . $site->getName() . '.' . $env->getName() . ' ===',
            'cyan',
            'bold'
        ));
        $this->line('Target method: ' . $this->paint(strtoupper($method), 'bold'));
        $this->line('');

        $counts = ['toggled' => 0, 'verified' => 0, 'ready' => 0, 'candidate_http' => 0, 'same_method' => 0, 'errors' => 0];
        foreach ($eligible as $state) {
            $result = $this->toggleMethod($site, $env, $state, $method);
            if ($result === true) {
                $counts['toggled']++;
            } elseif ($result === null) {
                $counts['errors']++;
            } else {
                $counts[$result]++;
            }
        }

        $this->line('');
        $this->line($this->paint('Summary', 'bold'));
        $row = fn(string $label, int $count) => sprintf('  %-25s %d', $label, $count);
        $this->line($this->paint($row('Toggled:', $counts['toggled']), 'green'));
        $labels = [
            'verified' => 'Skipped (active):',
            'ready' => 'Skipped (records ready):',
            'candidate_http' => 'Skipped (via Cloudflare):',
            'same_method' => "Skipped (already {$method}):",
        ];
        foreach ($labels as $key => $label) {
            if ($counts[$key] > 0) {
                $this->line($this->paint($row($label, $counts[$key]), 'cyan'));
            }
        }
        if ($counts['errors'] > 0) {
            $this->line($this->paint($row('Errors:', $counts['errors']), 'red'));
        }
        $this->line('');

        if ($counts['toggled'] > 0) {
            $this->line($this->paint(
                'Note: A site converge will reset the method to the default for the hostname type (custom -> DNS, platform -> HTTP).',
                'yellow'
            ));
            $this->line('');
        }

        return $counts['errors'] > 0 ? 1 : 0;
    }

    /**
     * Attempts to toggle the verify method on a single hostname.
     *
     * Returns true if toggled, a key of SKIP_REASONS if skipped, or null on
     * error.
     *
     * @return true|string|null
     */
    private function toggleMethod($site, $env, ChallengeState $state, string $method)
    {
        $hostname = $state->hostname();
        $requestedApiMethod = self::METHOD_TO_API[$method];

        // P15: never PATCH a domain that's active or ready, and never switch
        // a domain served through Cloudflare to HTTP.
        if ($state->active()) {
            $reason = 'verified';
        } elseif ($state->ready()) {
            $reason = 'ready';
        } elseif ($requestedApiMethod === 'http' && $state->candidate()) {
            $reason = 'candidate_http';
        } elseif ($state->verifyMethod() === $requestedApiMethod) {
            $reason = 'same_method';
        } else {
            $reason = null;
        }

        if ($reason !== null) {
            $label = self::SKIP_REASONS[$reason] ?? 'skipped (already ' . strtoupper($method) . ')';
            $this->line("  {$hostname} — " . $this->paint($label, 'cyan'));
            return $reason;
        }

        $updateUrl = sprintf(
            'sites/%s/environments/%s/hostnames/%s',
            $site->id,
            $env->id,
            rawurlencode($hostname)
        );

        try {
            $response = $this->request()->request($updateUrl, [
                'method' => 'PATCH',
                'form_params' => ['verify_method' => $requestedApiMethod],
            ]);
        } catch (TerminusException $e) {
            $this->line("  {$hostname} — " . $this->paint("error ({$e->getMessage()})", 'red'));
            return null;
        }

        if ($response->isError()) {
            $this->line("  {$hostname} — " . $this->paint('error (PATCH failed, HTTP ' . $response->getStatusCode() . ')', 'red'));
            return null;
        }

        $this->line("  {$hostname} — " . $this->paint('switched to ' . strtoupper($method), 'green'));
        return true;
    }

    private function renderChallengeInfo(ChallengeState $state, bool $wasToggled, ?string $toggledTo): void
    {
        // The re-GET may still show the old method while policy-docs
        // processes the change.
        $isHttp = ($toggledTo ?? $state->verifyMethod()) === 'http';

        $this->line('');
        $this->line($this->paint('=== Certificate Challenge Method ===', 'cyan', 'bold'));
        $this->line($this->paint($state->hostname(), 'yellow'));
        $this->line('------');
        $this->line('Current method: ' . $this->paint($isHttp ? 'HTTP' : 'DNS', 'bold'));

        if ($state->active()) {
            $this->line('Status: ' . $this->paint('ownership and certificate active', 'green'));
        } elseif ($state->ready()) {
            $this->line('Status: ' . $this->paint('DNS records ready (confirmed by Pantheon)', 'green'));
        } elseif ($isHttp) {
            $this->line($this->paint(
                'Domain ownership verification must be completed before HTTP-01 challenges can succeed.',
                'yellow'
            ));
            if ($state->candidate()) {
                $this->line($this->paint(
                    'This domain is served through Cloudflare today, which intercepts HTTP validation. Use DNS, and see "terminus gcdn:o2o".',
                    'yellow'
                ));
            }
        }
        $this->line('');

        if ($wasToggled) {
            $this->line($this->paint(
                'Note: A site converge will reset the method to the default for the hostname type (custom -> DNS, platform -> HTTP).',
                'yellow'
            ));
            $this->line('Validation values can change while Cloudflare processes the switch. If they look unchanged, run this command again in a minute.');
            $this->line('');
        }

        if ($isHttp) {
            $this->renderHttpChallenges($state);
        } else {
            $this->renderDnsChallenges($state);
        }
    }

    /**
     * The DCV delegation CNAME and, until the domain is active, the
     * ownership TXT. Never the raw cert TXT, which rotates (P5).
     */
    private function renderDnsChallenges(ChallengeState $state): void
    {
        $this->writeDcv($state);
        $this->line('');
        if (!$state->active()) {
            $this->writeOwnershipTxt($state, $state->ready() ? 'keep it until the domain is active' : 'add this');
            $this->line('');
        }
    }

    private function renderHttpChallenges(ChallengeState $state): void
    {
        $domain = $state->hostname();
        $trafficTarget = $state->trafficTarget();

        $this->line($this->paint('HTTP-01 via Cutover', 'bold'));
        $this->line('Point your DNS to Pantheon. Once traffic reaches the Cloudflare edge,');
        $this->line('the certificate will be issued automatically — no file placement needed.');
        if ($trafficTarget !== null) {
            $this->line('');
            $this->line("  CNAME {$domain} -> {$trafficTarget}.");
            $this->line('  Or use the A/AAAA records from "terminus gcdn:dns" for apex domains.');
        } else {
            $this->line('  Run "terminus gcdn:dns" for the DNS records to point to Pantheon.');
        }
        $this->line('');

        $http = $state->certHttp();
        if ($http !== null) {
            $this->line($this->paint('HTTP-01 via Serve File', 'bold'));
            $this->line('Serve the ACME challenge token on your web server:');
            $this->line("  URL:   {$http['name']}");
            $this->line("  Body:  {$http['value']}");
            $this->line('');
        }

        if (!$state->active()) {
            $this->writeOwnershipTxt($state, $state->ready() ? 'keep it until the domain is active' : 'add this');
            $this->line('');
        }
    }
}

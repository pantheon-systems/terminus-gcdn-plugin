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
 * Class O2oCommand.
 *
 * Generates the customer DNS record set for an orange-to-orange (O2O)
 * migration — when the customer's domain is already proxied through
 * Cloudflare in front of Pantheon's GCDN zone. Read-only.
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class O2oCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
    use SiteAwareTrait;
    use RequestAwareTrait;
    use DomainOutputTrait;

    /**
     * Generates customer DNS records for an orange-to-orange (O2O) migration,
     * where the customer's domain is served through Cloudflare today.
     *
     * Read-only. Exits 1 when a domain isn't ready yet, isn't found, or its
     * status is unavailable.
     *
     * @authorize
     *
     * @command gcdn:o2o
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string|null $domain Domain e.g. `example.com` (defaults to all GCDN custom domains on the environment)
     *
     * @usage <site>.<env> Generates O2O records for all GCDN custom domains on <site>'s <env> environment.
     * @usage <site>.<env> <domain_name> Generates O2O records for <domain_name> only.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function o2o($site_env, $domain = null)
    {
        $env = $this->getEnv($site_env);
        $site = $this->getSiteById($site_env);
        $siteEnv = $site->getName() . '.' . $env->getName();

        $domainsUrl = sprintf(
            'sites/%s/environments/%s/domains?hydrate[]=as_list&hydrate[]=recommendations',
            $site->id,
            $env->id
        );

        $response = $this->request()->request($domainsUrl, ['method' => 'get']);

        if ($response->isError()) {
            throw new TerminusException(
                'Failed to fetch domains for {site}.{env}.',
                ['site' => $site->getName(), 'env' => $env->getName()]
            );
        }

        $domains = $response->getData();
        if (!is_array($domains)) {
            $domains = [];
        }

        if ($domain !== null) {
            $domainInfo = $this->findDomain($domains, $domain);
            if ($domainInfo === null) {
                $this->line($this->paint("Domain not found on {$siteEnv}. Add it first:", 'red'));
                $this->line("  terminus domain:add {$siteEnv} {$domain}");
                $this->line('');
                return 1;
            }
            $state = new ChallengeState($domainInfo);
            if ($state->state() === ChallengeState::PLATFORM) {
                $this->line('Managed by Pantheon: platform domains don\'t need these records.');
                return 0;
            }
            if ($state->state() === ChallengeState::FASTLY) {
                $this->line("{$state->hostname()} isn't on GCDN (Cloudflare), so it doesn't need these records.");
                return 0;
            }
            $eligible = [$state];
        } else {
            // GCDN custom domains, plus any whose CDN status is unavailable.
            $eligible = [];
            foreach ($domains as $domainInfo) {
                if (!is_object($domainInfo) || empty($domainInfo->id)) {
                    continue;
                }
                $state = new ChallengeState($domainInfo);
                if ($state->isCustomCloudflare() || ($state->cdnUnavailable() && !$state->isPlatform())) {
                    $eligible[] = $state;
                }
            }
        }

        if (empty($eligible)) {
            $this->line("No GCDN custom domains found on {$siteEnv}");
            return 0;
        }

        $this->line('');
        $this->line($this->paint("=== Orange-to-Orange (O2O) records — {$siteEnv} ===", 'cyan', 'bold'));
        $this->line('Add these records in the Cloudflare zone that currently serves the domain.');
        $this->line('');

        $exit = 0;
        foreach ($eligible as $state) {
            if (!$this->renderDomain($state, $siteEnv)) {
                $exit = 1;
            }
        }
        return $exit;
    }

    /**
     * Renders one domain's record set. Returns whether it's ready.
     */
    private function renderDomain(ChallengeState $state, string $siteEnv): bool
    {
        $pad = '  ';
        $hostname = $state->hostname();
        $zone = $state->zone();

        $this->line($this->paint($hostname, 'yellow') . ($zone !== null ? "  (zone: {$zone})" : ''));
        $this->line('------');

        switch ($state->state()) {
            case ChallengeState::UNAVAILABLE:
                $this->line($this->paint('Status unavailable: Pantheon couldn\'t read this domain\'s CDN status. Try again shortly.', 'red'), $pad);
                $this->line('');
                return false;

            case ChallengeState::MISSING:
                // P12: gcdn:verify generates nothing; the status just isn't in yet.
                $this->line($this->paint('Status not available yet: Pantheon hasn\'t received this domain\'s Cloudflare status. Try again shortly.', 'yellow'), $pad);
                $this->line('');
                return false;

            case ChallengeState::ACTION_REQUIRED:
                $this->writeActionRequired($state, $siteEnv, false, $pad);
                $this->line('');
                return false;

            default:
                // Active, ready, candidate or pending: the same step tables as gcdn:verify, read-only
                // (no DNS lookups), with the proxied CNAME alone in step 3.
                $this->writeHeadline($state, $pad);
                $this->writeCloudflareStatus($state, $pad);
                if ($state->state() === ChallengeState::READY) {
                    $this->writeConfirmedDespiteErrors($state, 'See "Issues and Notes" in step 1.', $pad);
                }
                $this->writePaused($state, $siteEnv, false, $pad);
                $this->writeZoneHold($state, $pad);
                $this->line('', $pad);
                $this->writeSteps($state, $pad, [], false, $siteEnv, true);
                $this->line('');
                if (!in_array($state->state(), [ChallengeState::ACTIVE, ChallengeState::READY], true)) {
                    $this->writeNext($state, $siteEnv, false, $pad);
                    $this->line('');
                    return false;
                }
                return true;
        }
    }
}

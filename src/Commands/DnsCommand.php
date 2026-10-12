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
 * Class DnsCommand.
 *
 * Shows DNS records and Cloudflare verification records for every domain on
 * a site environment. Read-only.
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class DnsCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
    use SiteAwareTrait;
    use RequestAwareTrait;
    use DomainOutputTrait;

    /** States that leave a domain waiting on the customer or on Pantheon. */
    const NOT_READY = [ChallengeState::UNAVAILABLE, ChallengeState::MISSING, ChallengeState::CANDIDATE, ChallengeState::PENDING];

    /**
     * Shows DNS records and Cloudflare verification records for a site environment.
     *
     * Read-only. Exits 1 when any GCDN domain isn't ready yet or its status
     * is unavailable.
     *
     * @authorize
     *
     * @command gcdn:dns
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     *
     * @usage <site>.<env> Shows DNS and verification records for all domains on <site>'s <env> environment.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function dns($site_env)
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
        if (!is_array($domains) || empty($domains)) {
            $this->line("No domains found on {$siteEnv}");
            return 0;
        }

        $this->line('');
        $this->line($this->paint(
            '=== ' . $site->getName() . ' site on ' . $env->getName() . ' environment hostname dns records ===',
            'cyan',
            'bold'
        ));

        $notReady = [];
        foreach ($domains as $domainInfo) {
            if (!is_object($domainInfo) || empty($domainInfo->id)) {
                continue;
            }
            $state = new ChallengeState($domainInfo);
            $this->renderDomain($state, $domainInfo, $siteEnv);
            if (in_array($state->state(), self::NOT_READY, true)) {
                $notReady[] = $state->hostname();
            }
        }

        if ($notReady !== []) {
            $this->line('Not ready yet: ' . implode(', ', $notReady) . '.');
            $this->line("Add the records shown, then run \"terminus gcdn:verify {$siteEnv} <domain>\" to recheck.");
            $this->line('');
            return 1;
        }
        return 0;
    }

    private function renderDomain(ChallengeState $state, object $domainInfo, string $siteEnv): void
    {
        $pad = '  ';
        $this->line($this->paint($state->hostname(), 'yellow'));
        $this->line('------');
        $this->line('Type: ' . ($domainInfo->type ?? 'custom'));
        $this->line('');

        switch ($state->state()) {
            case ChallengeState::UNAVAILABLE:
                $this->line($this->paint('Status unavailable: Pantheon couldn\'t read this domain\'s CDN status. Try again shortly.', 'red'), $pad);
                break;

            case ChallengeState::PLATFORM:
                $this->line('Managed by Pantheon: no DNS records or verification needed.', $pad);
                break;

            case ChallengeState::FASTLY:
                $this->line('Not on GCDN (Cloudflare).', $pad);
                $this->writeTrafficRecords($state, $pad);
                break;

            case ChallengeState::MISSING:
                $this->line($this->paint('Cloudflare status not available yet. Try again shortly.', 'yellow'), $pad);
                $this->writeTrafficRecords($state, $pad);
                break;

            case ChallengeState::ACTIVE:
                $this->line('Cloudflare: ' . $this->paint('ownership and certificate active', 'green'), $pad);
                $this->line('', $pad);
                $this->writeTrafficRecords($state, $pad);
                $this->line('', $pad);
                $this->writeDcv($state, $pad);
                break;

            case ChallengeState::READY:
                $this->line('Cloudflare: ' . $this->paint('DNS records ready (confirmed by Pantheon)', 'green'), $pad);
                $this->writeCloudflareStatus($state, $pad);
                $this->writeZoneHold($state, $pad);
                $this->line('', $pad);
                $this->writeOwnershipTxt($state, 'keep it until the domain is active', $pad);
                $this->line('', $pad);
                $this->writeDcv($state, $pad);
                $this->line('', $pad);
                $this->writeTrafficRecords($state, $pad);
                $this->line('', $pad);
                $this->line('Served through Cloudflare today. For the ways to switch DNS, run:', $pad);
                $this->line("  terminus gcdn:verify {$siteEnv} {$state->hostname()}", $pad);
                break;

            case ChallengeState::CANDIDATE:
                $this->line('Cloudflare: ' . $this->paint('ownership not confirmed yet', 'red') . ' (served through Cloudflare today)', $pad);
                $this->writeCloudflareStatus($state, $pad);
                $this->writeZoneHold($state, $pad);
                $this->line('', $pad);
                $this->writeOwnershipTxt($state, 'add this', $pad);
                $this->line('', $pad);
                $this->writeDcv($state, $pad);
                $this->line('', $pad);
                $this->line($this->paint('Don\'t switch DNS yet: Pantheon hasn\'t confirmed ownership.', 'yellow'), $pad);
                $this->writeTrafficRecords($state, $pad);
                $this->writeErrors($state, $pad);
                $this->line("Cloudflare-to-Cloudflare steps: terminus gcdn:o2o {$siteEnv} {$state->hostname()}", $pad);
                break;

            default:
                $this->line('Cloudflare: ' . ($state->ownershipDoneCertPending()
                    ? $this->paint('ownership verified, certificate pending', 'yellow')
                    : $this->paint('ownership and certificate pending', 'red')), $pad);
                $this->writeCloudflareStatus($state, $pad);
                $this->writeZoneHold($state, $pad);
                $this->line('', $pad);
                if (!$state->ownershipDoneCertPending()) {
                    $this->writeOwnershipTxt($state, 'add this', $pad);
                    $this->line('', $pad);
                }
                $this->writeDcv($state, $pad);
                $this->line('', $pad);
                $this->writeTrafficRecords($state, $pad);
                $this->writeErrors($state, $pad);
                break;
        }
        $this->line('');
    }
}

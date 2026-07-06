<?php

namespace Pantheon\TerminusGCDN\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Request\RequestAwareTrait;
use Pantheon\TerminusGCDN\DcvZones;

/**
 * Class O2oCommand.
 *
 * Generates the customer DNS record set for an orange-to-orange (O2O)
 * migration — when the customer's domain is already proxied through their
 * own Cloudflare zone in front of Pantheon's GCDN zone.
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class O2oCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
    use SiteAwareTrait;
    use RequestAwareTrait;

    // ANSI color codes
    const YELLOW = "\033[33m";
    const GREEN = "\033[32m";
    const RED = "\033[31m";
    const CYAN = "\033[36m";
    const RESET = "\033[0m";
    const BOLD = "\033[1m";

    /**
     * Generates customer DNS records for an orange-to-orange (O2O) migration,
     * where the customer's domain is on their own Cloudflare zone.
     *
     * @authorize
     *
     * @command gcdn:o2o
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string|null $domain Domain e.g. `example.com` (defaults to all Cloudflare domains on the environment)
     *
     * @usage <site>.<env> Generates O2O records for all Cloudflare domains on <site>'s <env> environment.
     * @usage <site>.<env> <domain_name> Generates O2O records for <domain_name> only.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function o2o($site_env, $domain = null)
    {
        $env = $this->getEnv($site_env);
        $site = $this->getSiteById($site_env);

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

        // Only custom domains on Cloudflare need O2O records.
        $eligible = [];
        foreach ($domains as $domainInfo) {
            if (!is_object($domainInfo) || empty($domainInfo->id)) {
                continue;
            }
            $cdn = $domainInfo->cdn ?? 'fastly';
            $type = $domainInfo->type ?? 'custom';
            if ($type === 'custom' && in_array($cdn, ['cloudflare', 'both'])) {
                $eligible[] = $domainInfo;
            }
        }

        if ($domain !== null) {
            $eligible = array_values(array_filter(
                $eligible,
                fn($d) => strcasecmp($d->id, $domain) === 0
            ));
            if (empty($eligible)) {
                $this->output()->writeln(self::RED . 'Domain not found on this environment (or not on Cloudflare). Add it first:' . self::RESET);
                $this->output()->writeln('  terminus domain:add ' . $site->getName() . '.' . $env->getName() . ' ' . $domain);
                $this->output()->writeln('');
                return;
            }
        }

        if (empty($eligible)) {
            $this->output()->writeln('No Cloudflare custom domains found on ' . $site->getName() . '.' . $env->getName());
            return;
        }

        // The environment lives in a single GCDN zone, so any domain's
        // recommended CNAME target identifies the zone for all of them.
        $envZone = null;
        foreach ($eligible as $domainInfo) {
            $envZone = $this->zoneForDomain($domainInfo);
            if ($envZone !== null) {
                break;
            }
        }

        $this->output()->writeln('');
        $this->output()->writeln(
            self::CYAN . self::BOLD . '=== Orange-to-Orange (O2O) records — ' . $site->getName()
            . '.' . $env->getName() . ' ===' . self::RESET
        );
        $this->output()->writeln('Add these records in the Cloudflare zone that currently serves the domain.');
        $this->output()->writeln('');

        foreach ($eligible as $domainInfo) {
            $this->renderDomain($domainInfo, $envZone);
        }
    }

    /**
     * Determines the GCDN zone from a domain's recommended CNAME target.
     * Prefers target_dns, which is populated even before the domain exists
     * in public DNS; dns_status_details.dns_records only reflects records
     * detected on live DNS.
     */
    private function zoneForDomain($domainInfo): ?string
    {
        $recordSets = [];
        if (!empty($domainInfo->target_dns) && is_array($domainInfo->target_dns)) {
            $recordSets[] = $domainInfo->target_dns;
        }
        if (!empty($domainInfo->dns_status_details) && !empty($domainInfo->dns_status_details->dns_records)) {
            $recordSets[] = $domainInfo->dns_status_details->dns_records;
        }

        foreach ($recordSets as $records) {
            foreach ($records as $record) {
                if (!is_object($record)) {
                    continue;
                }
                $value = $record->target_value ?? $record->value ?? $record->recommended_value ?? '';
                $zone = DcvZones::zoneFromCnameTarget((string) $value);
                if ($zone !== null) {
                    return $zone;
                }
            }
        }
        return null;
    }

    /**
     * Renders the per-hostname O2O record set: ownership TXT, DCV
     * delegation CNAME, and traffic CNAME.
     */
    private function renderDomain($domainInfo, ?string $envZone)
    {
        $hostname = $domainInfo->id;
        $zone = $this->zoneForDomain($domainInfo) ?? $envZone;

        $this->output()->writeln(self::YELLOW . $hostname . self::RESET . ($zone !== null ? "  (zone: {$zone})" : ''));
        $this->output()->writeln('------');

        // Step 3: hostname ownership TXT.
        $challenges = $domainInfo->challenges ?? null;
        $verified = is_object($challenges) && !empty($challenges->verified) && $challenges->verified === true;
        $ownershipKey = null;
        $ownershipValue = null;
        if (is_object($challenges) && !empty($challenges->ownership_txt)) {
            $ownershipKey = $challenges->ownership_txt->key ?? null;
            $ownershipValue = $challenges->ownership_txt->val ?? null;
        }
        if (
            $ownershipKey === null
            && !empty($domainInfo->acme_preauthorization_challenges)
            && !empty($domainInfo->acme_preauthorization_challenges->{'dns-01'})
        ) {
            $dnsChallenge = $domainInfo->acme_preauthorization_challenges->{'dns-01'};
            $ownershipKey = $dnsChallenge->ownership_key ?? null;
            $ownershipValue = $dnsChallenge->ownership_value ?? null;
        }

        if ($verified) {
            $this->output()->writeln('  Hostname ownership: ' . self::GREEN . 'already verified' . self::RESET . ' — no TXT record needed.');
        } elseif ($ownershipKey !== null && $ownershipValue !== null) {
            $this->output()->writeln('  TXT — hostname ownership verification (removable once verified):');
            $this->output()->writeln("    Name:  {$ownershipKey}");
            $this->output()->writeln("    Value: {$ownershipValue}");
        } else {
            $this->output()->writeln(self::RED . '  Ownership TXT challenge unavailable — run "terminus gcdn:verify" for this domain to generate it.' . self::RESET);
        }
        $this->output()->writeln('');

        // DCV delegation CNAME.
        $dcvTarget = $zone !== null ? DcvZones::dcvTarget($hostname, $zone) : null;
        if ($dcvTarget !== null) {
            $this->output()->writeln('  CNAME — DCV delegation for certificate issuance + renewal (' . self::BOLD . 'DNS only / grey cloud' . self::RESET . '):');
            $this->output()->writeln("    Name:  _acme-challenge.{$hostname}");
            $this->output()->writeln("    Value: {$dcvTarget}");
            $this->output()->writeln("    Delete any existing _acme-challenge.{$hostname} TXT records first — they conflict with this CNAME.");
            $this->output()->writeln(self::YELLOW . '    Keep this record permanently — removing it breaks certificate renewal.' . self::RESET);
        } elseif ($zone !== null) {
            $this->output()->writeln(self::RED . "  Unknown GCDN zone \"{$zone}\" — no DCV delegation ID on file. Update DcvZones in the terminus-gcdn-plugin." . self::RESET);
        } else {
            $this->output()->writeln(self::RED . '  Could not determine the GCDN zone (no fe.<zone>.edge.pantheon.io CNAME recommendation found) — cannot build the DCV delegation record.' . self::RESET);
        }
        $this->output()->writeln('');

        // Traffic CNAME.
        $trafficTarget = $zone !== null ? "fe.{$zone}.edge.pantheon.io" : null;
        if ($trafficTarget !== null) {
            $this->output()->writeln('  CNAME — traffic (add ' . self::BOLD . 'LAST' . self::RESET . ', after ownership is verified and the certificate is active; Proxied or DNS only):');
            $this->output()->writeln("    Name:  {$hostname}");
            $this->output()->writeln("    Value: {$trafficTarget}");
        } else {
            $this->output()->writeln(self::RED . '  Could not determine the traffic CNAME target — run "terminus gcdn:dns" for this environment.' . self::RESET);
        }
        $this->output()->writeln('');
    }
}

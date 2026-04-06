<?php

namespace Pantheon\TerminusGCDN\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Request\RequestAwareTrait;

/**
 * Class DnsCommand.
 *
 * Shows DNS records and Cloudflare verification challenges for all
 * domains on a Cloudflare-migrated site environment.
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class DnsCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
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
     * Shows DNS records and Cloudflare verification challenges for a site environment.
     *
     * @authorize
     *
     * @command gcdn:dns
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     *
     * @usage <site>.<env> Shows DNS and challenge records for all domains on <site>'s <env> environment.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function dns($site_env)
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
        if (!is_array($domains) || empty($domains)) {
            $this->output()->writeln('No domains found on ' . $site->getName() . '.' . $env->getName());
            return;
        }

        $this->output()->writeln('');
        $this->output()->writeln(
            self::CYAN . self::BOLD . '=== ' . $site->getName() . ' site on ' . $env->getName()
            . ' environment hostname dns records ===' . self::RESET
        );

        foreach ($domains as $domainInfo) {
            if (!is_object($domainInfo) || empty($domainInfo->id)) {
                continue;
            }
            $this->renderDomain($domainInfo);
        }

        $this->output()->writeln('');
    }

    /**
     * Renders a single domain's DNS and challenge info.
     */
    private function renderDomain($domainInfo)
    {
        $domain = $domainInfo->id;
        $cdn = $domainInfo->cdn ?? 'fastly';
        $type = $domainInfo->type ?? 'custom';

        $this->output()->writeln(self::YELLOW . $domain . self::RESET);
        $this->output()->writeln('------');
        $this->output()->writeln("Type: {$type}");
        $this->output()->writeln('');

        // DNS records
        $matchedTypes = [];
        if (!empty($domainInfo->dns_status_details) && !empty($domainInfo->dns_status_details->dns_records)) {
            $this->output()->writeln('DNS Records:');
            $dnsRecords = [];
            foreach ($domainInfo->dns_status_details->dns_records as $record) {
                if (!is_object($record)) {
                    continue;
                }
                $rType = $record->type ?? $record->record_type ?? '';
                $value = $record->value ?? $record->recommended_value ?? $record->target_value ?? '';
                $detected = $record->detected_value ?? $record->current_value ?? '';
                $status = $record->status ?? '';
                $detectedMatches = !empty($detected) && strcasecmp(trim($detected), trim($value)) === 0;

                if ($detectedMatches) {
                    $matchedTypes[] = $rType;
                }

                $line = "  {$rType}: {$value}";
                if ($detected) {
                    $line .= " (current: {$detected})";
                }
                if ($status === 'action_required') {
                    if ($detectedMatches) {
                        $line .= self::YELLOW . ' [propagating verification]' . self::RESET;
                    } else {
                        $line .= self::RED . ' [action required]' . self::RESET;
                    }
                } elseif ($status) {
                    $line .= " [{$status}]";
                }
                $this->output()->writeln($line);
            }

            $allThreeMatch = in_array('CNAME', $matchedTypes) && in_array('A', $matchedTypes) && in_array('AAAA', $matchedTypes);
            if ($allThreeMatch) {
                $this->output()->writeln('');
                $this->output()->writeln(self::YELLOW . '  Caution: Both CNAME and A/AAAA records are configured. Use either a CNAME or A/AAAA records — not both.' . self::RESET);
            } elseif ($type === 'custom') {
                $this->output()->writeln(self::CYAN . '  Note: Use either A/AAAA records OR a CNAME — not both.' . self::RESET);
            }
        }

        // Skip challenges for non-Cloudflare domains
        if (!in_array($cdn, ['cloudflare', 'both'])) {
            $this->output()->writeln('');
            return;
        }

        // Cloudflare challenges
        $hasChallenges = false;
        if (!empty($domainInfo->challenges)) {
            $challenges = $domainInfo->challenges;
            $verified = !empty($challenges->verified) && $challenges->verified === true;

            if ($verified) {
                $this->output()->writeln('  Cloudflare ownership: ' . self::GREEN . 'verified' . self::RESET);
            } else {
                $this->output()->writeln('  Cloudflare ownership: ' . self::RED . 'not verified' . self::RESET);

                if (!empty($challenges->ownership_txt)) {
                    $this->output()->writeln('');
                    $this->output()->writeln('  TXT Record — Domain Ownership:');
                    $this->output()->writeln("    Name:  {$challenges->ownership_txt->key}");
                    $this->output()->writeln("    Value: {$challenges->ownership_txt->val}");
                    $hasChallenges = true;
                }

                if (!empty($challenges->cert_txt)) {
                    $this->output()->writeln('');
                    $this->output()->writeln('  TXT Record — Certificate Validation:');
                    $this->output()->writeln("    Name:  {$challenges->cert_txt->key}");
                    $this->output()->writeln("    Value: {$challenges->cert_txt->val}");
                    $hasChallenges = true;
                }

                if (!empty($challenges->ownership_errors)) {
                    $this->output()->writeln('');
                    foreach ($challenges->ownership_errors as $err) {
                        $this->output()->writeln(self::RED . "  Warning: {$err}" . self::RESET);
                    }
                }
            }
        }

        // Legacy challenges fallback
        if (
            !$hasChallenges
            && !empty($domainInfo->acme_preauthorization_challenges)
            && !empty($domainInfo->acme_preauthorization_challenges->{'dns-01'})
        ) {
            $dnsChallenge = $domainInfo->acme_preauthorization_challenges->{'dns-01'};

            if (!empty($dnsChallenge->ownership_key) && !empty($dnsChallenge->ownership_value)) {
                $this->output()->writeln('');
                $this->output()->writeln('  TXT Record — Domain Ownership:');
                $this->output()->writeln("    Name:  {$dnsChallenge->ownership_key}");
                $this->output()->writeln("    Value: {$dnsChallenge->ownership_value}");
            }

            if (!empty($dnsChallenge->verification_key) && !empty($dnsChallenge->verification_value)) {
                $this->output()->writeln('');
                $this->output()->writeln('  TXT Record — Certificate Validation:');
                $this->output()->writeln("    Name:  {$dnsChallenge->verification_key}");
                $this->output()->writeln("    Value: {$dnsChallenge->verification_value}");
            }
        }

        $this->output()->writeln('');
    }
}

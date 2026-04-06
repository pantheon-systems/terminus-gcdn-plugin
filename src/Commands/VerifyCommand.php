<?php

namespace Pantheon\TerminusGCDN\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Request\RequestAwareTrait;

/**
 * Class VerifyCommand.
 *
 * Verifies domain ownership and DNS configuration for Cloudflare-migrated sites.
 * Checks both Cloudflare Custom Hostname ownership and DNS record status.
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class VerifyCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
    use SiteAwareTrait;
    use RequestAwareTrait;

    const YELLOW = "\033[33m";
    const GREEN = "\033[32m";
    const RED = "\033[31m";
    const CYAN = "\033[36m";
    const RESET = "\033[0m";
    const BOLD = "\033[1m";

    /**
     * Verifies ownership and DNS configuration of a domain on a Cloudflare-migrated site.
     *
     * @authorize
     *
     * @command gcdn:verify
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $domain Domain e.g. `example.com`
     *
     * @usage <site>.<env> <domain_name> Verifies ownership and DNS of <domain_name> on <site>'s <env> environment.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function verify($site_env, $domain)
    {
        $env = $this->getEnv($site_env);
        $site = $this->getSiteById($site_env);

        // Step 1: Trigger verification via verify-ownership endpoint.
        $verifyUrl = sprintf(
            'sites/%s/environments/%s/hostnames/%s/verify-ownership',
            $site->id,
            $env->id,
            rawurlencode($domain)
        );

        $response = $this->request()->request($verifyUrl, [
            'method' => 'POST',
            'form_params' => ['challenge_type' => 'dns-01'],
        ]);

        if ($response->isError()) {
            $this->log()->warning(
                'Could not trigger verification for {domain}. Checking status...',
                ['domain' => $domain]
            );
        }

        $verifyData = $response->getData();
        $ownershipVerified = is_object($verifyData) && !empty($verifyData->verified) && $verifyData->verified === true;

        // Step 2: Fetch domain DNS status and challenges.
        $this->output()->writeln('');
        $this->output()->writeln(
            self::CYAN . self::BOLD . '=== ' . $site->getName() . ' site on ' . $env->getName()
            . ' environment ===' . self::RESET
        );
        $this->output()->writeln(self::YELLOW . $domain . self::RESET);
        $this->output()->writeln('------');

        $domainsUrl = sprintf(
            'sites/%s/environments/%s/domains?hydrate[]=as_list&hydrate[]=recommendations',
            $site->id,
            $env->id
        );

        $domainsResponse = $this->request()->request($domainsUrl, ['method' => 'get']);

        if ($domainsResponse->isError()) {
            $this->output()->writeln(self::RED . 'Could not fetch DNS status.' . self::RESET);
            $this->output()->writeln('Run "terminus domain:dns ' . $site->getName() . '.' . $env->getName() . '" for DNS records.');
            return;
        }

        $domainsData = $domainsResponse->getData();

        // Find our domain in the list.
        $domainInfo = null;
        if (is_array($domainsData)) {
            foreach ($domainsData as $d) {
                if (is_object($d) && !empty($d->id) && $d->id === $domain) {
                    $domainInfo = $d;
                    break;
                }
            }
        }

        if ($domainInfo === null) {
            $this->output()->writeln(self::RED . 'Domain not found. Add it first:' . self::RESET);
            $this->output()->writeln('  terminus domain:add ' . $site->getName() . '.' . $env->getName() . ' ' . $domain);
            $this->output()->writeln('');
            return;
        }

        $cdn = $domainInfo->cdn ?? 'fastly';
        $type = $domainInfo->type ?? 'custom';
        $this->output()->writeln("Type: {$type}");
        $this->output()->writeln('');

        // Step 3: Check DNS record status.
        $dnsConfigured = false;
        $dnsRecords = [];
        $matchedTypes = [];
        if (is_object($domainInfo) && !empty($domainInfo->dns_status_details) && !empty($domainInfo->dns_status_details->dns_records)) {
            $hasRecords = false;
            foreach ($domainInfo->dns_status_details->dns_records as $record) {
                if (!is_object($record)) {
                    continue;
                }
                $hasRecords = true;
                $rType = $record->type ?? $record->record_type ?? '';
                $value = $record->value ?? $record->recommended_value ?? $record->target_value ?? '';
                $detected = $record->detected_value ?? $record->current_value ?? '';
                $status = $record->status ?? '';

                // Check if detected value matches the target
                $detectedMatches = !empty($detected) && strcasecmp(trim($detected), trim($value)) === 0;
                if ($detectedMatches) {
                    $matchedTypes[] = $rType;
                }

                $dnsRecords[] = [
                    'type' => $rType,
                    'value' => $value,
                    'detected' => $detected,
                    'status' => $status,
                    'matches' => $detectedMatches,
                ];
            }

            // DNS is configured if CNAME matches, or both A and AAAA match
            $cnameMatches = in_array('CNAME', $matchedTypes);
            $aMatches = in_array('A', $matchedTypes);
            $aaaaMatches = in_array('AAAA', $matchedTypes);
            $dnsConfigured = $hasRecords && ($cnameMatches || ($aMatches && $aaaaMatches));
        }

        // Step 4: Check challenges from Cloudflare format.
        // For Cloudflare hostnames, challenges.verified is the source of truth.
        $challengesReady = false;
        if (is_object($domainInfo) && !empty($domainInfo->challenges)) {
            $challenges = $domainInfo->challenges;
            $challengesReady = !empty($challenges->ready) && $challenges->ready === true;

            if (in_array($cdn, ['cloudflare', 'both']) && isset($challenges->verified)) {
                $ownershipVerified = $challenges->verified === true;
            }
        }

        // Step 5: Report status.
        $allThreeMatch = in_array('CNAME', $matchedTypes) && in_array('A', $matchedTypes) && in_array('AAAA', $matchedTypes);

        if ($ownershipVerified && $dnsConfigured) {
            $this->output()->writeln('Cloudflare ownership: ' . self::GREEN . 'verified' . self::RESET);
            $this->output()->writeln('DNS: ' . self::GREEN . 'configured correctly' . self::RESET);
            $this->output()->writeln('');
            $this->renderDnsRecords($dnsRecords);
            if ($allThreeMatch) {
                $this->output()->writeln(self::YELLOW . '  Caution: Both CNAME and A/AAAA records are configured. Use either a CNAME or A/AAAA records — not both.' . self::RESET);
                $this->output()->writeln('');
            }
            return;
        }

        if ($ownershipVerified && !$dnsConfigured) {
            $this->output()->writeln('Cloudflare ownership: ' . self::GREEN . 'verified' . self::RESET);
            $this->output()->writeln('DNS: ' . self::RED . 'not configured' . self::RESET);
            $this->output()->writeln('');
            $this->output()->writeln('DNS Records:');
            $this->renderDnsRecords($dnsRecords);
            $this->output()->writeln('');
            return;
        }

        // Ownership not verified.
        $this->output()->writeln('Cloudflare ownership: ' . self::RED . 'not verified' . self::RESET);
        $this->output()->writeln('');

        $hasChallengeRecords = false;

        // Cloudflare challenges format.
        if (is_object($domainInfo) && !empty($domainInfo->challenges)) {
            $challenges = $domainInfo->challenges;

            if (!empty($challenges->ownership_txt)) {
                $this->output()->writeln('TXT Record — Domain Ownership:');
                $this->output()->writeln("  Name:  {$challenges->ownership_txt->key}");
                $this->output()->writeln("  Value: {$challenges->ownership_txt->val}");
                $this->output()->writeln('');
                $hasChallengeRecords = true;
            }

            if (!empty($challenges->cert_txt)) {
                $this->output()->writeln('TXT Record — Certificate Validation:');
                $this->output()->writeln("  Name:  {$challenges->cert_txt->key}");
                $this->output()->writeln("  Value: {$challenges->cert_txt->val}");
                $this->output()->writeln('');
                $hasChallengeRecords = true;
            }

            if (!empty($challenges->ownership_errors)) {
                foreach ($challenges->ownership_errors as $err) {
                    $this->output()->writeln(self::RED . "Warning: {$err}" . self::RESET);
                }
                $this->output()->writeln('');
            }
        }

        // Legacy format fallback.
        if (
            !$hasChallengeRecords
            && !empty($domainInfo->acme_preauthorization_challenges)
            && !empty($domainInfo->acme_preauthorization_challenges->{'dns-01'})
        ) {
            $dnsChallenge = $domainInfo->acme_preauthorization_challenges->{'dns-01'};

            if (!empty($dnsChallenge->ownership_key) && !empty($dnsChallenge->ownership_value)) {
                $this->output()->writeln('TXT Record — Domain Ownership:');
                $this->output()->writeln("  Name:  {$dnsChallenge->ownership_key}");
                $this->output()->writeln("  Value: {$dnsChallenge->ownership_value}");
                $this->output()->writeln('');
                $hasChallengeRecords = true;
            }

            if (!empty($dnsChallenge->verification_key) && !empty($dnsChallenge->verification_value)) {
                $this->output()->writeln('TXT Record — Certificate Validation:');
                $this->output()->writeln("  Name:  {$dnsChallenge->verification_key}");
                $this->output()->writeln("  Value: {$dnsChallenge->verification_value}");
                $this->output()->writeln('');
                $hasChallengeRecords = true;
            }
        }

        if ($hasChallengeRecords) {
            $this->output()->writeln('Add the TXT records above to your DNS provider, then re-run this command.');
            $this->output()->writeln('');
        }

        // DNS records.
        if (!empty($dnsRecords)) {
            $this->output()->writeln('DNS Records:');
            $this->renderDnsRecords($dnsRecords);
            $this->output()->writeln('');
        }
    }

    /**
     * Renders DNS records with appropriate status indicators.
     */
    private function renderDnsRecords(array $dnsRecords)
    {
        $allThreeMatch = true;
        $matchedTypes = [];
        foreach ($dnsRecords as $rec) {
            if (!empty($rec['matches'])) {
                $matchedTypes[] = $rec['type'];
            }
        }
        $allThreeMatch = in_array('CNAME', $matchedTypes) && in_array('A', $matchedTypes) && in_array('AAAA', $matchedTypes);

        foreach ($dnsRecords as $rec) {
            $line = "  {$rec['type']}: {$rec['value']}";
            if ($rec['detected']) {
                $line .= " (current: {$rec['detected']})";
            }

            if ($rec['status'] === 'action_required') {
                if (!empty($rec['matches'])) {
                    $line .= self::YELLOW . ' [propagating verification]' . self::RESET;
                } else {
                    $line .= self::RED . ' [action required]' . self::RESET;
                }
            } elseif ($rec['status']) {
                $line .= " [{$rec['status']}]";
            }
            $this->output()->writeln($line);
        }

        if ($allThreeMatch) {
            $this->output()->writeln('');
            $this->output()->writeln(self::YELLOW . '  Caution: Both CNAME and A/AAAA records are configured. Use either a CNAME or A/AAAA records — not both.' . self::RESET);
        }
    }
}

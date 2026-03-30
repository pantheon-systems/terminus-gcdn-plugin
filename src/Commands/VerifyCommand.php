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
        $this->log()->notice('Checking DNS status for {domain}...', ['domain' => $domain]);

        $domainsUrl = sprintf(
            'sites/%s/environments/%s/domains?hydrate[]=as_list&hydrate[]=recommendations',
            $site->id,
            $env->id
        );

        $domainsResponse = $this->request()->request($domainsUrl, ['method' => 'get']);

        if ($domainsResponse->isError()) {
            if ($ownershipVerified) {
                $this->log()->notice('Ownership verified, but could not fetch DNS status.');
            }
            $this->log()->notice(
                'Run "terminus domain:dns {site}.{env}" to see required DNS records.',
                ['site' => $site->getName(), 'env' => $env->getName()]
            );
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
            $this->log()->warning(
                '{domain} not found on {site}.{env}. Add it first with "terminus domain:add {site}.{env} {domain}".',
                [
                    'domain' => $domain,
                    'site' => $site->getName(),
                    'env' => $env->getName(),
                ]
            );
            return;
        }

        // Step 3: Check DNS record status.
        $dnsConfigured = false;
        $dnsRecords = [];
        if (is_object($domainInfo) && !empty($domainInfo->dns_status_details) && !empty($domainInfo->dns_status_details->dns_records)) {
            $allCorrect = true;
            $hasRecords = false;
            foreach ($domainInfo->dns_status_details->dns_records as $record) {
                if (!is_object($record)) {
                    continue;
                }
                $hasRecords = true;
                $type = $record->type ?? $record->record_type ?? '';
                $value = $record->value ?? $record->recommended_value ?? $record->target_value ?? '';
                $detected = $record->detected_value ?? $record->current_value ?? '';
                $status = $record->status ?? '';
                $dnsRecords[] = ['type' => $type, 'value' => $value, 'detected' => $detected, 'status' => $status];
                if ($status === 'action_required') {
                    $allCorrect = false;
                }
            }
            $dnsConfigured = $hasRecords && $allCorrect;
        }

        // Step 4: Check challenges from Cloudflare format.
        // For Cloudflare hostnames, challenges.verified is the source of truth —
        // the root verified field is set by domain:add, not by Cloudflare verification.
        $challengesReady = false;
        $cdn = $domainInfo->cdn ?? '';
        if (is_object($domainInfo) && !empty($domainInfo->challenges)) {
            $challenges = $domainInfo->challenges;
            $challengesReady = !empty($challenges->ready) && $challenges->ready === true;

            if (in_array($cdn, ['cloudflare', 'both']) && isset($challenges->verified)) {
                $ownershipVerified = $challenges->verified === true;
            }
        }

        // Step 5: Report status.
        if ($ownershipVerified && $dnsConfigured) {
            $this->log()->notice(
                '{domain} on {site}.{env} — ownership verified and DNS configured correctly.',
                ['domain' => $domain, 'site' => $site->getName(), 'env' => $env->getName()]
            );
            return;
        }

        if ($ownershipVerified && !$dnsConfigured) {
            $this->log()->notice(
                '{domain} — ownership verified, but DNS is not configured yet.',
                ['domain' => $domain]
            );
            $this->log()->notice('Update your DNS records:' . PHP_EOL);
            foreach ($dnsRecords as $rec) {
                $line = "  {$rec['type']}: {$rec['value']}";
                if ($rec['detected']) {
                    $line .= " (current: {$rec['detected']})";
                }
                if ($rec['status'] === 'action_required') {
                    $line .= " [action required]";
                }
                $this->output()->writeln($line);
            }
            $this->output()->writeln('');
            $this->log()->notice(
                'Run "terminus domain:dns {site}.{env}" for the full DNS record list.',
                ['site' => $site->getName(), 'env' => $env->getName()]
            );
            return;
        }

        // Ownership not verified — show challenge records.
        $this->log()->warning(
            '{domain} on {site}.{env} — ownership has not been verified yet.',
            ['domain' => $domain, 'site' => $site->getName(), 'env' => $env->getName()]
        );

        $hasChallengeRecords = false;

        // Try Cloudflare challenges format first.
        if (is_object($domainInfo) && !empty($domainInfo->challenges)) {
            $challenges = $domainInfo->challenges;

            if (!empty($challenges->ownership_txt)) {
                $this->log()->notice(
                    'TXT Record 1 — Domain Ownership Verification:' . PHP_EOL . PHP_EOL
                    . '  Name:  {key}' . PHP_EOL
                    . '  Value: {value}' . PHP_EOL,
                    ['key' => $challenges->ownership_txt->key, 'value' => $challenges->ownership_txt->val]
                );
                $hasChallengeRecords = true;
            }

            if (!empty($challenges->cert_txt)) {
                $this->log()->notice(
                    'TXT Record 2 — Certificate Validation:' . PHP_EOL . PHP_EOL
                    . '  Name:  {key}' . PHP_EOL
                    . '  Value: {value}' . PHP_EOL,
                    ['key' => $challenges->cert_txt->key, 'value' => $challenges->cert_txt->val]
                );
                $hasChallengeRecords = true;
            }

            if (!empty($challenges->ownership_errors)) {
                $this->log()->warning(
                    'Ownership errors: {errors}',
                    ['errors' => implode(', ', $challenges->ownership_errors)]
                );
            }
        }

        // Fall back to acme_preauthorization_challenges format.
        if (
            !$hasChallengeRecords
            && is_object($domainInfo)
            && !empty($domainInfo->acme_preauthorization_challenges)
            && !empty($domainInfo->acme_preauthorization_challenges->{'dns-01'})
        ) {
            $dnsChallenge = $domainInfo->acme_preauthorization_challenges->{'dns-01'};

            if (!empty($dnsChallenge->ownership_key) && !empty($dnsChallenge->ownership_value)) {
                $this->log()->notice(
                    'TXT Record 1 — Domain Ownership Verification:' . PHP_EOL . PHP_EOL
                    . '  Name:  {key}' . PHP_EOL
                    . '  Value: {value}' . PHP_EOL,
                    ['key' => $dnsChallenge->ownership_key, 'value' => $dnsChallenge->ownership_value]
                );
                $hasChallengeRecords = true;
            }

            if (!empty($dnsChallenge->verification_key) && !empty($dnsChallenge->verification_value)) {
                $this->log()->notice(
                    'TXT Record 2 — Certificate Validation:' . PHP_EOL . PHP_EOL
                    . '  Name:  {key}' . PHP_EOL
                    . '  Value: {value}' . PHP_EOL,
                    ['key' => $dnsChallenge->verification_key, 'value' => $dnsChallenge->verification_value]
                );
                $hasChallengeRecords = true;
            }
        }

        if ($hasChallengeRecords) {
            $this->log()->notice('Add the TXT records above to your DNS provider, then re-run this command.');
        }

        // Always show required DNS records.
        if (!empty($dnsRecords)) {
            $this->log()->notice(PHP_EOL . 'Required DNS records:' . PHP_EOL);
            foreach ($dnsRecords as $rec) {
                $line = "  {$rec['type']}: {$rec['value']}";
                if ($rec['detected']) {
                    $line .= " (current: {$rec['detected']})";
                }
                if ($rec['status'] === 'action_required') {
                    $line .= " [action required]";
                }
                $this->output()->writeln($line);
            }
            $this->output()->writeln('');
        }

        $this->log()->notice(
            'Run "terminus domain:dns {site}.{env}" for the full DNS record list.',
            ['site' => $site->getName(), 'env' => $env->getName()]
        );
    }
}

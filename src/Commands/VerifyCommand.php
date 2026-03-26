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
 * Verifies domain ownership for Cloudflare-migrated sites by triggering
 * verification and displaying the 2 TXT records required by Cloudflare:
 * one for domain ownership and one for certificate validation.
 *
 * Based on Terminus core domain:verify by Conor Bauer, modified for
 * Cloudflare-specific TXT record format.
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class VerifyCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
    use SiteAwareTrait;
    use RequestAwareTrait;

    /**
     * Verifies ownership of a domain on a Cloudflare-migrated site.
     *
     * Triggers DNS-01 verification and displays the 2 Cloudflare TXT records
     * (ownership + certificate) if verification is not yet complete.
     *
     * @authorize
     *
     * @command gcdn:verify
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $domain Domain e.g. `example.com`
     *
     * @usage <site>.<env> <domain_name> Verifies ownership of <domain_name> on <site>'s <env> environment.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function verify($site_env, $domain)
    {
        $env = $this->getEnv($site_env);
        $site = $this->getSiteById($site_env);

        // Trigger verification via the existing verify-ownership endpoint.
        $verifyUrl = sprintf(
            'sites/%s/environments/%s/hostnames/%s/verify-ownership',
            $site->id,
            $env->id,
            rawurlencode($domain)
        );

        $response = $this->request()->request($verifyUrl, [
            'method' => 'POST',
            'json' => ['challenge_type' => 'dns-01'],
        ]);

        if ($response->isError()) {
            throw new TerminusException(
                'Ownership verification failed for {domain} on {site}.{env}.',
                [
                    'domain' => $domain,
                    'site' => $site->getName(),
                    'env' => $env->getName(),
                ]
            );
        }

        $data = $response->getData();

        // The Cloudflare verify-ownership endpoint returns {"verified": bool}.
        if (is_object($data) && !empty($data->verified) && $data->verified === true) {
            $this->log()->notice(
                'Ownership of {domain} on {site}.{env} has been verified.',
                [
                    'domain' => $domain,
                    'site' => $site->getName(),
                    'env' => $env->getName(),
                ]
            );
            return;
        }

        // Verification not yet complete. Poll for up to 60 seconds.
        $this->log()->notice('Verifying ownership of {domain}...', ['domain' => $domain]);

        $domainUrl = sprintf(
            'sites/%s/environments/%s/domains/%s',
            $site->id,
            $env->id,
            rawurlencode($domain)
        );

        for ($i = 0; $i < 12; $i++) {
            sleep(5);
            $domainResponse = $this->request()->request($domainUrl, [
                'method' => 'get',
            ]);
            $domainData = $domainResponse->getData();

            // Check Cloudflare-style verification (challenges.ready)
            if (
                is_object($domainData)
                && !empty($domainData->challenges)
                && $domainData->challenges->ready === true
            ) {
                $this->log()->notice(
                    'Ownership of {domain} on {site}.{env} has been verified.',
                    [
                        'domain' => $domain,
                        'site' => $site->getName(),
                        'env' => $env->getName(),
                    ]
                );
                return;
            }

            // Also check Fastly-style verification (ownership_status)
            if (
                is_object($domainData)
                && !empty($domainData->ownership_status)
                && !empty($domainData->ownership_status->preprovision_result)
                && $domainData->ownership_status->preprovision_result->status === 'success'
            ) {
                $this->log()->notice(
                    'Ownership of {domain} on {site}.{env} has been verified.',
                    [
                        'domain' => $domain,
                        'site' => $site->getName(),
                        'env' => $env->getName(),
                    ]
                );
                return;
            }
        }

        // Verification did not complete — display DNS challenge info.
        $this->log()->warning(
            'Ownership of {domain} on {site}.{env} has not been verified yet.',
            [
                'domain' => $domain,
                'site' => $site->getName(),
                'env' => $env->getName(),
            ]
        );

        // Try Cloudflare challenge format (2 TXT records).
        if (
            is_object($domainData)
            && !empty($domainData->challenges)
        ) {
            $challenges = $domainData->challenges;
            $hasRecords = false;

            if (!empty($challenges->ownership_txt)) {
                $this->log()->notice(
                    'TXT Record 1 — Domain Ownership Verification:' . PHP_EOL . PHP_EOL
                    . '  Name:  {key}' . PHP_EOL
                    . '  Value: {value}' . PHP_EOL,
                    [
                        'key' => $challenges->ownership_txt->key,
                        'value' => $challenges->ownership_txt->val,
                    ]
                );
                $hasRecords = true;
            }

            if (!empty($challenges->cert_txt)) {
                $this->log()->notice(
                    'TXT Record 2 — Certificate Validation:' . PHP_EOL . PHP_EOL
                    . '  Name:  {key}' . PHP_EOL
                    . '  Value: {value}' . PHP_EOL,
                    [
                        'key' => $challenges->cert_txt->key,
                        'value' => $challenges->cert_txt->val,
                    ]
                );
                $hasRecords = true;
            }

            if (!empty($challenges->ownership_errors)) {
                $this->log()->warning(
                    'Ownership verification errors: {errors}',
                    ['errors' => implode(', ', $challenges->ownership_errors)]
                );
            }

            if (!empty($challenges->cert_errors)) {
                $this->log()->warning(
                    'Certificate validation errors: {errors}',
                    ['errors' => implode(', ', $challenges->cert_errors)]
                );
            }

            if ($hasRecords) {
                $this->log()->notice(
                    'Add both TXT records to your DNS provider, then re-run this command to verify.'
                );
                return;
            }
        }

        // Fall back to Fastly-style ACME challenge format (1 TXT record).
        if (
            is_object($domainData)
            && !empty($domainData->acme_preauthorization_challenges)
            && !empty($domainData->acme_preauthorization_challenges->{'dns-01'})
        ) {
            $dnsChallenge = $domainData->acme_preauthorization_challenges->{'dns-01'};
            $this->log()->notice(
                'Add the following TXT record to your DNS provider:' . PHP_EOL . PHP_EOL
                . '  Name:  {key}' . PHP_EOL
                . '  Value: {value}' . PHP_EOL,
                [
                    'key' => $dnsChallenge->verification_key ?? '_acme-challenge.' . $domain,
                    'value' => $dnsChallenge->verification_value,
                ]
            );
            $this->log()->notice(
                'Once the TXT record is in place, re-run this command to verify.'
            );
            return;
        }

        $this->log()->notice(
            'Ensure your DNS TXT records are configured correctly and re-run this command.'
        );
    }
}

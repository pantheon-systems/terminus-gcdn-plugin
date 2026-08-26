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
 * Class ChallengeCommand.
 *
 * Shows or toggles the certificate challenge method (DNS or HTTP) for a
 * domain on a Cloudflare-migrated site. Aligns with the dashboard's
 * CertChallengePanel toggle.
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class ChallengeCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
    use SiteAwareTrait;
    use RequestAwareTrait;

    const YELLOW = "\033[33m";
    const GREEN = "\033[32m";
    const RED = "\033[31m";
    const CYAN = "\033[36m";
    const RESET = "\033[0m";
    const BOLD = "\033[1m";

    const VALID_METHODS = ['dns', 'http'];
    const METHOD_TO_API = ['dns' => 'txt', 'http' => 'http'];

    /**
     * Shows or toggles the certificate challenge method for a domain.
     *
     * Without --method, displays the current challenge method and records.
     * With --method, switches the method and displays updated records.
     *
     * Note: A site converge resets the method to the hostname-type default
     * (custom → dns, platform → http).
     *
     * @authorize
     *
     * @command gcdn:challenge
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $domain Domain e.g. `example.com`
     * @option method Set the challenge method: dns or http
     *
     * @usage <site>.<env> <domain> Shows the current challenge method and records for <domain>.
     * @usage <site>.<env> <domain> --method=http Switches <domain> to HTTP certificate challenges.
     * @usage <site>.<env> <domain> --method=dns Switches <domain> to DNS certificate challenges.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function challenge($site_env, $domain, $options = ['method' => null])
    {
        $env = $this->getEnv($site_env);
        $site = $this->getSiteById($site_env);
        $method = $options['method'];

        if ($method !== null) {
            $method = strtolower($method);
            if (!in_array($method, self::VALID_METHODS)) {
                throw new TerminusException(
                    'Invalid method "{method}". Use "dns" or "http".',
                    ['method' => $method]
                );
            }

            $updateUrl = sprintf(
                'sites/%s/environments/%s/hostnames/%s',
                $site->id,
                $env->id,
                rawurlencode($domain)
            );

            $apiMethod = self::METHOD_TO_API[$method];
            $response = $this->request()->request($updateUrl, [
                'method' => 'PATCH',
                'form_params' => ['verify_method' => $apiMethod],
            ]);

            if ($response->isError()) {
                throw new TerminusException(
                    'Failed to update challenge method for {domain}.',
                    ['domain' => $domain]
                );
            }

            $this->log()->notice(
                'Challenge method updated to {method} for {domain}.',
                ['method' => strtoupper($method), 'domain' => $domain]
            );
        }

        $domainsUrl = sprintf(
            'sites/%s/environments/%s/domains?hydrate[]=as_list&hydrate[]=recommendations',
            $site->id,
            $env->id
        );

        $domainsResponse = $this->request()->request($domainsUrl, ['method' => 'get']);

        if ($domainsResponse->isError()) {
            throw new TerminusException(
                'Failed to fetch domains for {site}.{env}.',
                ['site' => $site->getName(), 'env' => $env->getName()]
            );
        }

        $domainsData = $domainsResponse->getData();
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
            throw new TerminusException(
                'Domain {domain} not found on {site}.{env}.',
                ['domain' => $domain, 'site' => $site->getName(), 'env' => $env->getName()]
            );
        }

        $cdn = $domainInfo->cdn ?? 'fastly';
        if (!in_array($cdn, ['cloudflare', 'both'])) {
            $this->output()->writeln(
                self::RED . 'This domain is not on Cloudflare. '
                . 'Challenge method toggle is only available for GCDN-migrated domains.' . self::RESET
            );
            return;
        }

        $this->renderChallengeInfo($domainInfo, $method !== null);
    }

    private function renderChallengeInfo($domainInfo, $wasToggled)
    {
        $domain = $domainInfo->id;
        $currentMethod = $domainInfo->verify_method ?? null;
        $isHttp = $currentMethod === 'http';
        $displayMethod = $isHttp ? 'HTTP' : 'DNS';

        $this->output()->writeln('');
        $this->output()->writeln(
            self::CYAN . self::BOLD . '=== Certificate Challenge Method ===' . self::RESET
        );
        $this->output()->writeln(self::YELLOW . $domain . self::RESET);
        $this->output()->writeln('------');
        $this->output()->writeln('Current method: ' . self::BOLD . $displayMethod . self::RESET);
        $this->output()->writeln('');

        if ($wasToggled) {
            $this->output()->writeln(
                self::YELLOW . 'Note: A site converge will reset the method to the default '
                . 'for the hostname type (custom -> DNS, platform -> HTTP).' . self::RESET
            );
            $this->output()->writeln('');
        }

        if ($isHttp) {
            $this->renderHttpChallenges($domainInfo);
        } else {
            $this->renderDnsChallenges($domainInfo);
        }
    }

    private function renderDnsChallenges($domainInfo)
    {
        $domain = $domainInfo->id;
        $challenges = $domainInfo->challenges ?? null;
        $acme = $domainInfo->acme_preauthorization_challenges ?? null;
        $hasChallenges = false;

        $zone = $this->detectZone($domainInfo);
        $dcvTarget = $zone !== null ? DcvZones::dcvTarget($domain, $zone) : null;

        if ($dcvTarget !== null) {
            $this->output()->writeln(
                self::BOLD . 'DCV Delegation CNAME' . self::RESET . ' '
                . self::GREEN . '(Recommended)' . self::RESET
            );
            $this->output()->writeln('Add a permanent CNAME for automatic certificate renewals (DNS only / grey cloud):');
            $this->output()->writeln("  Name:  _acme-challenge.{$domain}");
            $this->output()->writeln("  Value: {$dcvTarget}.");
            $this->output()->writeln(self::YELLOW . '  Keep this record permanently — removing it breaks certificate renewal.' . self::RESET);
            $this->output()->writeln('');
            $hasChallenges = true;
        }

        if (is_object($challenges) && !empty($challenges->cert_txt)) {
            $this->output()->writeln(
                self::BOLD . 'TXT Record — Certificate Validation' . self::RESET . ' '
                . self::RED . '(Not recommended — value rotates)' . self::RESET
            );
            $this->output()->writeln("  Name:  {$challenges->cert_txt->key}");
            $this->output()->writeln("  Value: {$challenges->cert_txt->val}");
            $this->output()->writeln('');
            $hasChallenges = true;
        }

        if (is_object($challenges) && !empty($challenges->ownership_txt)) {
            $verified = !empty($challenges->verified) && $challenges->verified === true;
            if ($verified) {
                $this->output()->writeln('Hostname ownership: ' . self::GREEN . 'verified' . self::RESET);
            } else {
                $this->output()->writeln(self::BOLD . 'TXT Record — Domain Ownership' . self::RESET);
                $this->output()->writeln("  Name:  {$challenges->ownership_txt->key}");
                $this->output()->writeln("  Value: {$challenges->ownership_txt->val}");
            }
            $this->output()->writeln('');
            $hasChallenges = true;
        }

        if (
            !$hasChallenges
            && is_object($acme)
            && !empty($acme->{'dns-01'})
        ) {
            $dnsChallenge = $acme->{'dns-01'};
            if (!empty($dnsChallenge->verification_key) && !empty($dnsChallenge->verification_value)) {
                $this->output()->writeln(self::BOLD . 'TXT Record — Certificate Validation' . self::RESET);
                $this->output()->writeln("  Name:  {$dnsChallenge->verification_key}");
                $this->output()->writeln("  Value: {$dnsChallenge->verification_value}");
                $this->output()->writeln('');
            }
            if (!empty($dnsChallenge->ownership_key) && !empty($dnsChallenge->ownership_value)) {
                $this->output()->writeln(self::BOLD . 'TXT Record — Domain Ownership' . self::RESET);
                $this->output()->writeln("  Name:  {$dnsChallenge->ownership_key}");
                $this->output()->writeln("  Value: {$dnsChallenge->ownership_value}");
                $this->output()->writeln('');
            }
        }
    }

    private function renderHttpChallenges($domainInfo)
    {
        $challenges = $domainInfo->challenges ?? null;
        $acme = $domainInfo->acme_preauthorization_challenges ?? null;

        $this->output()->writeln(self::BOLD . 'HTTP-01 via Cutover' . self::RESET);
        $this->output()->writeln(
            'Point your DNS to Pantheon and the certificate will be issued automatically.'
        );
        $this->output()->writeln('No additional action needed after DNS cutover.');
        $this->output()->writeln('');

        $hasHttp = false;

        if (is_object($challenges) && !empty($challenges->cert_http)) {
            $this->output()->writeln(self::BOLD . 'HTTP-01 via Serve File' . self::RESET);
            $this->output()->writeln('Serve the ACME challenge token on your web server:');
            $this->output()->writeln("  URL:   {$challenges->cert_http->key}");
            $this->output()->writeln("  Body:  {$challenges->cert_http->val}");
            $this->output()->writeln('');
            $hasHttp = true;
        }

        if (
            !$hasHttp
            && is_object($acme)
            && !empty($acme->{'http-01'})
        ) {
            $httpChallenge = $acme->{'http-01'};
            if (!empty($httpChallenge->verification_key) && !empty($httpChallenge->verification_value)) {
                $this->output()->writeln(self::BOLD . 'HTTP-01 via Serve File' . self::RESET);
                $this->output()->writeln('Serve the ACME challenge token on your web server:');
                $this->output()->writeln("  URL:   {$httpChallenge->verification_key}");
                $this->output()->writeln("  Body:  {$httpChallenge->verification_value}");
                $this->output()->writeln('');
            }
        }

        if (is_object($challenges) && !empty($challenges->ownership_txt)) {
            $verified = !empty($challenges->verified) && $challenges->verified === true;
            if ($verified) {
                $this->output()->writeln('Hostname ownership: ' . self::GREEN . 'verified' . self::RESET);
            } else {
                $this->output()->writeln(self::BOLD . 'TXT Record — Domain Ownership' . self::RESET);
                $this->output()->writeln("  Name:  {$challenges->ownership_txt->key}");
                $this->output()->writeln("  Value: {$challenges->ownership_txt->val}");
            }
            $this->output()->writeln('');
        }
    }

    private function detectZone($domainInfo): ?string
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
}

<?php

namespace Pantheon\TerminusGCDN\Commands;

use Pantheon\TerminusGCDN\ChallengeState;

/**
 * Output helpers shared by gcdn:verify, gcdn:dns, gcdn:o2o and
 * gcdn:challenge.
 *
 * Colors are only written when the output is decorated, so `--no-ansi` and
 * piped output stay plain (P16). Raw ANSI codes bypass Symfony's formatter,
 * which is why this checks isDecorated() itself.
 */
trait DomainOutputTrait
{
    private static array $styles = [
        'yellow' => "\033[33m",
        'green' => "\033[32m",
        'red' => "\033[31m",
        'cyan' => "\033[36m",
        'bold' => "\033[1m",
    ];

    protected function paint(string $text, string ...$styles): string
    {
        if ($styles === [] || !$this->output()->isDecorated()) {
            return $text;
        }
        $codes = implode('', array_map(fn($style) => self::$styles[$style], $styles));
        return $codes . $text . "\033[0m";
    }

    protected function line(string $text = '', string $pad = ''): void
    {
        $this->output()->writeln($text === '' ? '' : $pad . $text);
    }

    /**
     * Finds a domain by name, ignoring case (P14).
     */
    protected function findDomain(array $domains, string $name): ?object
    {
        foreach ($domains as $domain) {
            if (is_object($domain) && !empty($domain->id) && strcasecmp((string) $domain->id, $name) === 0) {
                return $domain;
            }
        }
        return null;
    }

    /**
     * "Cloudflare status: hostname pending, certificate active (as of ...)",
     * so a stale status is visible as stale. Nothing before #318 deploys.
     */
    protected function writeCloudflareStatus(ChallengeState $state, string $pad = ''): void
    {
        $hostname = $state->cloudflareStatus();
        $ssl = $state->cloudflareSslStatus();
        if ($hostname === null && $ssl === null) {
            return;
        }
        $parts = [];
        if ($hostname !== null) {
            $parts[] = 'hostname ' . $hostname;
        }
        if ($ssl !== null) {
            $parts[] = 'certificate ' . $ssl;
        }
        $observed = $state->observedAt();
        $this->line(
            'Cloudflare status: ' . implode(', ', $parts) . ($observed !== null ? " (as of {$observed})" : ''),
            $pad
        );
    }

    protected function writeZoneHold(ChallengeState $state, string $pad = ''): void
    {
        if ($state->zoneHold()) {
            $this->line($this->paint(
                'Cloudflare reports that this domain is in a held zone. Contact Pantheon support.',
                'red'
            ), $pad);
        }
    }

    protected function writeOwnershipTxt(ChallengeState $state, string $note, string $pad = ''): void
    {
        $txt = $state->ownershipTxt();
        if ($txt === null) {
            $this->line($this->paint('Ownership TXT record: not available yet. Try again shortly.', 'yellow'), $pad);
            return;
        }
        $this->line($this->paint('TXT record — domain ownership', 'bold') . " ({$note}):", $pad);
        $this->line("  Name:  {$txt['name']}", $pad);
        $this->line("  Value: {$txt['value']}", $pad);
    }

    /**
     * The DCV delegation CNAME, never the raw cert TXT (P5). It stays in
     * place for renewals.
     */
    protected function writeDcv(ChallengeState $state, string $pad = ''): void
    {
        $dcv = $state->dcv();
        if ($dcv === null) {
            $this->line($this->paint(
                'Certificate validation record: Pantheon couldn\'t determine it for this domain. Contact Pantheon support.',
                'red'
            ), $pad);
            return;
        }
        $this->line(
            $this->paint('CNAME record — certificate validation (DCV delegation)', 'bold') . ', DNS only / not proxied:',
            $pad
        );
        $this->line("  Name:  {$dcv['name']}", $pad);
        $this->line("  Value: {$dcv['value']}.", $pad);
        $this->line("  Remove any TXT records at {$dcv['name']} first; they conflict with this CNAME.", $pad);
        $this->line($this->paint('  Keep this record permanently: certificate renewals need it.', 'yellow'), $pad);
    }

    /**
     * The traffic records (what DNS should point to), with the values
     * detected today.
     */
    protected function writeTrafficRecords(ChallengeState $state, string $pad = ''): void
    {
        $records = $state->dnsRecords();
        if ($records === []) {
            $target = $state->trafficTarget();
            if ($target === null) {
                $this->line('Traffic records: run "terminus gcdn:dns" for this environment.', $pad);
                return;
            }
            $this->line($this->paint('Traffic record', 'bold') . ':', $pad);
            $this->line("  CNAME {$state->hostname()} -> {$target}.", $pad);
            return;
        }

        $this->line($this->paint('Traffic records', 'bold') . ' (use either the CNAME or the A/AAAA records, not both):', $pad);
        $matched = [];
        foreach ($records as $record) {
            $text = "  {$record['type']}: {$record['value']}";
            if ($record['detected'] !== '') {
                $text .= " (current: {$record['detected']})";
            }
            if ($record['matches']) {
                $matched[] = $record['type'];
            }
            if ($record['status'] === 'action_required') {
                $text .= $record['matches']
                    ? $this->paint(' [propagating]', 'yellow')
                    : $this->paint(' [action required]', 'red');
            } elseif ($record['status'] !== '') {
                $text .= " [{$record['status']}]";
            }
            $this->line($text, $pad);
        }
        if (in_array('CNAME', $matched, true) && in_array('A', $matched, true) && in_array('AAAA', $matched, true)) {
            $this->line($this->paint(
                '  Caution: both the CNAME and the A/AAAA records are set. Use one or the other.',
                'yellow'
            ), $pad);
        }
    }

    protected function writeErrors(ChallengeState $state, string $pad = ''): void
    {
        foreach ($state->ownershipErrors() as $error) {
            $this->line($this->paint("Ownership: {$error}", 'yellow'), $pad);
        }
        foreach ($state->certErrors() as $error) {
            $this->line($this->paint("Certificate: {$error}", 'yellow'), $pad);
        }
    }

    /**
     * The cutover options for a domain that's ready but served through
     * Cloudflare today. Classification (own zone vs. another host) isn't
     * available yet, so both paths are shown. Never a timeline.
     */
    protected function writeCutoverMessage(ChallengeState $state, string $siteEnv, string $pad = ''): void
    {
        $domain = $state->hostname();
        $this->line('This domain is currently served through Cloudflare, either from your own Cloudflare account', $pad);
        $this->line('or by your current host (for example WP Engine or Kinsta). Switch DNS to Pantheon whenever you\'re ready:', $pad);
        $this->line('- Your own Cloudflare account: you can switch with proxying on. Turning proxying off for this', $pad);
        $this->line('  record until the domain is active keeps the switch simple and downtime minimal.', $pad);
        $this->line('- Your current host: you can switch now. Cloudflare errors 1016 or 1034 may appear briefly', $pad);
        $this->line('  until Cloudflare moves the domain to Pantheon. To avoid them, add the TXT record below,', $pad);
        $this->line('  contact Pantheon support to request a release, and switch right after it\'s confirmed.', $pad);
        $this->line('  Support confirms the record name.', $pad);
        $txt = $state->ownershipTxt();
        if ($txt !== null) {
            $this->line("    Name:  cf-dns-verify.{$domain}", $pad);
            $this->line("    Value: {$txt['value']}", $pad);
        }
        $this->line('', $pad);
        $this->line(
            'When you\'re ready, switch DNS, then run: '
            . $this->paint("terminus gcdn:verify {$siteEnv} {$domain}", 'bold'),
            $pad
        );
    }
}

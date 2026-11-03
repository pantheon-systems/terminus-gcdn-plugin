<?php

namespace Pantheon\TerminusGCDN\Commands;

use Pantheon\TerminusGCDN\ChallengeState;
use Pantheon\TerminusGCDN\CloudflareIps;
use Symfony\Component\Console\Terminal;

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
        'blue' => "\033[94m",
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
            $parts[] = 'hostname ' . $this->paint($hostname, $this->statusColor($hostname));
        }
        if ($ssl !== null) {
            $parts[] = 'certificate ' . $this->paint($ssl, $this->statusColor($ssl));
        }
        $observed = $state->observedAt();
        $this->line(
            'Cloudflare status: ' . implode(', ', $parts) . ($observed !== null ? " (as of {$observed})" : ''),
            $pad
        );
    }

    /**
     * Cloudflare's own status values: green when active, red when blocked,
     * deleted, expired or timed out, yellow while pending.
     */
    private function statusColor(string $value): string
    {
        if ($value === 'active') {
            return 'green';
        }
        return preg_match('/blocked|delet|expired|timed_out|inactive/', $value) ? 'red' : 'yellow';
    }

    /**
     * The one-line state: light blue, green once active. Action required
     * has its own red block (writeActionRequired).
     */
    protected function writeHeadline(ChallengeState $state, string $pad = ''): void
    {
        if ($state->active()) {
            $text = $this->paint('ownership and certificate active', 'green');
        } elseif ($state->ready()) {
            $text = $this->paint('DNS records ready (confirmed by Pantheon)', 'blue');
        } elseif ($state->candidate()) {
            $text = $this->paint('ownership not confirmed yet', 'blue');
        } elseif ($state->ownershipDoneCertPending()) {
            $text = $this->paint('ownership verified, certificate pending', 'blue');
        } else {
            $text = $this->paint('ownership and certificate pending', 'blue');
        }
        $this->line('Cloudflare: ' . $text, $pad);
    }

    /**
     * The three steps to a GCDN domain, each its own lined table: the step
     * and its status on top, in the status color (red: not done, yellow:
     * caution, green: done), then each record with the action it needs.
     *
     * $found: what gcdn:verify's own DNS lookups found for the TXT and the
     * DCV CNAME (['txt' => string[]|null, 'dcv' => string[]|null]; null
     * when not looked up or the lookup failed). $certTxt: step 2 shows the
     * `_acme-challenge` TXT instead of the CNAME (gcdn:verify --cert-txt).
     * $o2o: step 3 is the proxied CNAME alone (gcdn:o2o).
     */
    protected function writeSteps(ChallengeState $state, string $pad = '', array $found = [], bool $certTxt = false, ?string $siteEnv = null, bool $o2o = false): void
    {
        $this->writeOwnershipStep($state, $found, $pad);
        $this->line('');
        $this->writeCertificateStep($state, $found, $certTxt, $pad);
        $this->line('');
        $this->writeTrafficStep($state, $pad, $siteEnv, $o2o);
    }

    private function writeOwnershipStep(ChallengeState $state, array $found, string $pad): void
    {
        $txt = $state->ownershipTxt();
        $row = fn(array $action) => $txt !== null ? ['TXT', $txt['name'], $txt['value'], $action] : null;
        if ($state->active() || $state->ownershipDoneCertPending()) {
            [$status, $color] = ['done', 'green'];
            // P3: once Cloudflare confirms ownership, the TXT isn't shown.
            $rows = [['span' => 'None: Cloudflare has confirmed ownership.', 'color' => 'green']];
        } elseif ($txt === null) {
            [$status, $color] = ['not done', 'red'];
            $rows = [$this->notice('Issue: Ownership record', 'The TXT record isn\'t available yet.', 'yellow', 'Try again shortly.')];
        } elseif ($state->ready()) {
            [$status, $color] = ['done (confirmed by Pantheon)', 'green'];
            $rows = [$row(['None: confirmed by Pantheon. Keep it until the domain is active.', 'green'])];
        } else {
            $action = $this->txtAction($found['txt'] ?? null, $txt['value']);
            [$status, $color] = $action[1] === 'green' ? ['in DNS, waiting for confirmation', 'yellow'] : ['not done: add this record', 'red'];
            $rows = [$row($action)];
        }
        // Cloudflare's ownership errors, word for word, with what can be done about them.
        if ($state->ownershipErrors() !== [] && !$state->active() && !$state->ownershipDoneCertPending()) {
            $rows[] = $this->notice('Issue: Ownership', $this->bullets($state->ownershipErrors()), $state->ready() ? 'yellow' : 'red', $this->ownershipFix($state));
        }
        $this->writeStepTable("1. Ownership (TXT record): {$status}", $color, $rows, $pad);
    }

    /** Several of Cloudflare's errors as bullets; one as it is. */
    private function bullets(array $lines): string
    {
        return count($lines) > 1 ? '- ' . implode("\n- ", $lines) : implode('', $lines);
    }

    /** A notice row for a step's "Issues and Notes": $fix, when set, goes under "What can be done". */
    private function notice(string $type, string $details, string $color = '', ?string $fix = null): array
    {
        return ['notice' => $type, 'details' => $details, 'color' => $color, 'fix' => $fix];
    }

    /** What can be done about Cloudflare's ownership errors, by state. */
    private function ownershipFix(ChallengeState $state): string
    {
        if ($state->ready()) {
            return "- If you own or have access to the domain's Cloudflare account: you can turn proxying off for this record "
                . 'and switch DNS to Pantheon, which reduces downtime. Turning proxying off has another benefit, if the record '
                . 'points directly to your origin server: turn it off before you switch, wait for the change to propagate, and '
                . "run gcdn:verify. Cloudflare then tries the _cf-custom-hostname pre-validation again. This is optional.\n"
                . '- If your current host uses Cloudflare for SaaS (for example WP Engine or Kinsta): switch DNS to Pantheon and '
                . 'remove the domain from your current host at the same time, if your host allows it (WP Engine, for example, '
                . 'lets you remove a domain without taking the site down). That speeds up the cutover and clears Cloudflare '
                . 'errors 1014, 1016 or 1034 sooner.';
        }
        if ($state->candidate()) {
            return 'Add the TXT record above. Cloudflare can\'t check it while the domain is served through Cloudflare, so '
                . 'Pantheon confirms ownership through it instead. Don\'t switch DNS until this step is done.';
        }
        return 'Make sure the TXT record above is in DNS with the value shown, then run gcdn:verify.';
    }

    private function writeCertificateStep(ChallengeState $state, array $found, bool $certTxt, string $pad): void
    {
        $done = $state->active() || $state->ready();
        if ($certTxt && !$done) {
            [$status, $color, $rows] = $this->certTxtStep($state, $found);
            $this->writeStepTable("2. Certificate (TXT record): {$status}", $color, array_merge($rows, $this->certErrorNotices($state, true)), $pad);
            return;
        }

        $dcv = $state->dcv();
        $notDone = $state->ownershipDoneCertPending()
            ? 'not done: Cloudflare issues the certificate once this record is in place'
            : 'not done: add this record';
        if ($dcv === null) {
            $rows = [$this->notice('Issue: Certificate record', 'Pantheon couldn\'t determine the certificate validation record for this domain.', 'red', 'Contact Pantheon support.')];
            $this->writeStepTable('2. Certificate (DCV CNAME): ' . ($done ? 'done' : $notDone), $done ? 'green' : 'red', $rows, $pad);
            return;
        }

        $cnames = $found['dcv'] ?? null;
        $has = $cnames ? 'DNS has: ' . $cnames[0] . (preg_match('/\.dcv\.cloudflare\.com\.?$/i', $cnames[0])
            ? " (another Cloudflare account's certificate validation, likely the current host)" : '') : '';
        $matches = $cnames && $this->foundValue($cnames, $dcv['value']);
        if ($done) {
            if ($cnames === null || $matches) {
                [$status, $color, $action] = ['done', 'green', [$matches ? 'None: found in DNS.' : 'None.', 'green']];
            } elseif ($cnames === []) {
                [$status, $color, $action] = ['done, but the record isn\'t in DNS', 'yellow', ['Recommended: add it.', 'yellow']];
            } else {
                [$status, $color, $action] = ['done, but DNS has another value', 'yellow', ["Recommended: change it to this value. {$has}", 'yellow']];
            }
            $rows = [
                ["CNAME\n(DNS only)", $dcv['name'], "{$dcv['value']}.", $action],
                $this->notice('Note: Renewals', $this->renewalAdvice($cnames, $dcv), $color === 'yellow' ? 'yellow' : ''),
            ];
            if ($certTxt) {
                $rows[] = $this->notice('Note: TXT record', 'No TXT record needed now: the certificate is active, and the CNAME is what renewals can rely on.');
            }
            $this->writeStepTable("2. Certificate (DCV CNAME): {$status}", $color, $rows, $pad);
            return;
        }

        if ($cnames === null) {
            $action = ['Add this record. Remove any TXT records with this name first: they conflict with it.', 'red'];
        } elseif ($cnames === []) {
            $action = !empty($found['dcvTxt'])
                ? ['Delete the TXT records with this name, then add this record: they conflict with it.', 'red']
                : ['Add this record: not in DNS yet.', 'red'];
        } elseif ($matches) {
            $action = ['None: found in DNS.', 'green'];
        } else {
            $action = ["Change it to this value. {$has}", 'red'];
        }
        if (!$matches) {
            $action[0] .= ' Can\'t use a CNAME with this name? Run gcdn:verify with --cert-txt for a TXT record instead.';
        }
        [$status, $color] = $matches ? ['in DNS, waiting for Cloudflare to issue the certificate', 'yellow'] : [$notDone, 'red'];
        $this->writeStepTable("2. Certificate (DCV CNAME): {$status}", $color, array_merge([
            ["CNAME\n(DNS only)", $dcv['name'], "{$dcv['value']}.", $action],
        ], $this->certErrorNotices($state, false)), $pad);
    }

    /** Cloudflare's certificate errors, word for word, while step 2 isn't done. */
    private function certErrorNotices(ChallengeState $state, bool $certTxt): array
    {
        if ($state->certErrors() === []) {
            return [];
        }
        return [$this->notice('Issue: Certificate', $this->bullets($state->certErrors()), 'red', $certTxt
            ? 'Make sure the TXT record above is in DNS with the value shown, then run gcdn:verify with --cert-txt.'
            : 'Make sure the CNAME above is in DNS with the value shown, DNS only, with no TXT records at the same name. Then run gcdn:verify.')];
    }

    private function writeTrafficStep(ChallengeState $state, string $pad, ?string $siteEnv, bool $o2o): void
    {
        $active = $state->active();
        $open = $active || $state->ready();
        if ($active && $state->trafficSwitched()) {
            [$status, $color] = ['done', 'green'];
        } elseif ($open) {
            [$status, $color] = ['not done: switch whenever you\'re ready', 'red'];
        } else {
            [$status, $color] = [$state->ownershipDoneCertPending() ? 'not yet: after step 2' : 'not yet: after steps 1 and 2', 'red'];
        }
        $this->writeTrafficRecords($state, $pad, "3. Switch traffic to Pantheon: {$status}", $color, $open, $siteEnv, $o2o);
    }

    /**
     * The action for a TXT record, from what gcdn:verify found in DNS
     * ($values; null when not looked up, or the lookup failed).
     */
    private function txtAction(?array $values, string $expected): array
    {
        if ($values === null) {
            return ['Add this record.', 'red'];
        }
        if ($values === []) {
            return ['Add this record: not in DNS yet.', 'red'];
        }
        if ($this->foundValue($values, $expected)) {
            return ['None: found in DNS.', 'green'];
        }
        return ['Change it to this value. DNS has: ' . implode(', ', $values), 'red'];
    }

    /**
     * $value in lines of at most $width, keeping its own line breaks. Lines
     * break after a space, dot, colon, comma or hyphen, so a hostname or an
     * address list wraps at its parts; a part longer than $width is cut.
     */
    private function wrapText(string $value, int $width): array
    {
        $lines = [];
        foreach (explode("\n", $value) as $paragraph) {
            $line = '';
            foreach (preg_split('/(?<=[ .:,\-])/', $paragraph) as $part) {
                if ($line !== '' && strlen(rtrim($line . $part)) > $width) {
                    $lines[] = rtrim($line);
                    $line = '';
                }
                $line .= $part;
                while (strlen(rtrim($line)) > $width) {
                    $lines[] = substr($line, 0, $width);
                    $line = substr($line, $width);
                }
            }
            $lines[] = rtrim($line);
        }
        return $lines;
    }

    /**
     * Sentences in lines of at most $width, breaking only at spaces. A line
     * that starts with "- " is a bullet: its later lines line up under its text.
     */
    private function wrapProse(string $value, int $width): array
    {
        $lines = [];
        foreach (explode("\n", $value) as $paragraph) {
            $indent = str_starts_with($paragraph, '- ') ? '  ' : '';
            $wrapped = explode("\n", wordwrap(substr($paragraph, strlen($indent)), $width - strlen($indent), "\n", true));
            foreach ($wrapped as $n => $part) {
                $lines[] = ($indent === '' ? '' : ($n === 0 ? '- ' : '  ')) . $part;
            }
        }
        return $lines;
    }

    /** The terminal width the tables fit, from Symfony's Terminal (COLUMNS, then stty). */
    protected function tableWidth(): int
    {
        return (new Terminal())->getWidth();
    }

    private function foundValue(array $values, string $expected): bool
    {
        $normalize = fn(string $v) => strtolower(rtrim(trim($v, " \"\t"), '.'));
        return in_array($normalize($expected), array_map($normalize, $values), true);
    }

    /**
     * A lined table for one step: $title across the top in $color. A row
     * is a record [type, name, value, [action, color]], or ['span' => text,
     * 'color' => color] across the whole table. Each record becomes a Type /
     * Name / Value row with an "Action required" row under it, and a column
     * header opens each run of records.
     *
     * Names and values stay whole, so they copy in one piece: a terminal
     * copies a wrapped cell line by line, borders included. They wrap (the
     * wider first, down to 20) only when the terminal can't fit them.
     */
    private function writeStepTable(string $title, string $color, array $rows, string $pad): void
    {
        $label = 'Action required';
        $lines = [];
        $previous = 'span';
        $noticed = false;
        foreach ($rows as $row) {
            if (isset($row['span'])) {
                $lines[] = ['kind' => 'span', 'cells' => [[$row['span'], $row['color'] ?? '']]];
                $previous = 'span';
                continue;
            }
            if (isset($row['notice'])) {
                // "Issues and Notes" opens the notices; a column header opens each run of them.
                if (!$noticed) {
                    $lines[] = ['kind' => 'span', 'cells' => [['Issues and Notes', 'bold']]];
                    $noticed = true;
                    $previous = 'span';
                }
                if ($previous !== 'pair') {
                    $lines[] = ['kind' => 'pair', 'cells' => [['Notice type', 'bold'], ['Details', 'bold']]];
                }
                $lines[] = ['kind' => 'pair', 'cells' => [[$row['notice'], $row['color']], [$row['details'], '']]];
                $previous = 'pair';
                if ($row['fix'] !== null) {
                    $lines[] = ['kind' => 'span', 'cells' => [['What can be done', 'bold']]];
                    $lines[] = ['kind' => 'span', 'cells' => [[$row['fix'], '']]];
                    $previous = 'span';
                }
                continue;
            }
            [$type, $name, $value, $action] = array_values($row);
            if ($previous === 'span') {
                $lines[] = ['kind' => 'head', 'cells' => [['Type', 'bold'], ['Name', 'bold'], ['Value', 'bold']]];
            }
            $lines[] = ['kind' => 'cells', 'cells' => [[$type, ''], [$name, ''], [$value, '']]];
            $lines[] = ['kind' => 'action', 'cells' => [[$label, 'bold'], $action]];
            $previous = 'cells';
        }

        $widths = [strlen($label), strlen('Name'), strlen('Value')];
        foreach ($lines as $line) {
            if ($line['kind'] === 'pair') {
                $widths[0] = max($widths[0], strlen($line['cells'][0][0]));
            }
        }
        foreach ($lines as $line) {
            if ($line['kind'] === 'cells') {
                foreach ($line['cells'] as $i => $cell) {
                    foreach (explode("\n", $cell[0]) as $part) {
                        $widths[$i] = max($widths[$i], strlen($part));
                    }
                }
            }
        }
        // Inside the borders: three columns, their padding, and the two " │ " between them.
        $available = $this->tableWidth() - strlen($pad) - 4;
        $inner = function () use (&$widths): int {
            return array_sum($widths) + 6;
        };
        while ($inner() > $available && max($widths[1], $widths[2]) > 20) {
            $widths[$widths[1] >= $widths[2] ? 1 : 2]--;
        }
        // Wider, so the title, the full-width rows and the actions fit on one line when the terminal allows.
        $longest = strlen($title);
        foreach ($lines as $line) {
            $second = in_array($line['kind'], ['action', 'pair'], true);
            if ($line['kind'] === 'cells' || $line['kind'] === 'head') {
                continue;
            }
            foreach (explode("\n", $line['cells'][$second ? 1 : 0][0]) as $part) {
                $longest = max($longest, ($second ? $widths[0] + 3 : 0) + strlen($part));
            }
        }
        $widths[2] += max(0, min($longest, $available) - $inner());
        $total = $inner();

        // Where each kind of row has an inner border: after Type, after Name.
        $bounds = ['span' => [], 'head' => [0, 1], 'cells' => [0, 1], 'action' => [0], 'pair' => [0]];
        $cellWidths = [
            'span' => [$total],
            'head' => $widths,
            'cells' => $widths,
            'action' => [$widths[0], $widths[1] + $widths[2] + 3],
            'pair' => [$widths[0], $widths[1] + $widths[2] + 3],
        ];
        $rule = function (string $left, string $right, ?string $above, ?string $below) use ($widths, $bounds, $pad): string {
            $out = $left;
            foreach ($widths as $i => $width) {
                $out .= str_repeat('─', $width + 2);
                if ($i < 2) {
                    $up = $above !== null && in_array($i, $bounds[$above], true);
                    $down = $below !== null && in_array($i, $bounds[$below], true);
                    $out .= $up && $down ? '┼' : ($up ? '┴' : ($down ? '┬' : '─'));
                }
            }
            return $pad . $out . $right;
        };
        $row = function (string $kind, array $cells, array $extra = []) use ($cellWidths, $pad): void {
            $columns = [];
            foreach ($cells as $i => $cell) {
                // Names and values break at their dots; sentences only at spaces.
                $columns[$i] = $kind === 'cells'
                    ? $this->wrapText($cell[0], $cellWidths[$kind][$i])
                    : $this->wrapProse($cell[0], $cellWidths[$kind][$i]);
            }
            for ($n = 0, $height = max(array_map('count', $columns)); $n < $height; $n++) {
                $parts = [];
                foreach ($cells as $i => $cell) {
                    $part = $columns[$i][$n] ?? '';
                    $styles = array_values(array_filter(array_merge([$cell[1]], $extra)));
                    $parts[] = ($part !== '' && $styles !== [] ? $this->paint($part, ...$styles) : $part)
                        . str_repeat(' ', $cellWidths[$kind][$i] - strlen($part));
                }
                $this->output()->writeln($pad . '│ ' . implode(' │ ', $parts) . ' │');
            }
        };

        $this->output()->writeln($rule('┌', '┐', null, 'span'));
        $row('span', [[$title, $color]], ['bold']);
        $previous = 'span';
        foreach ($lines as $line) {
            $this->output()->writeln($rule('├', '┤', $previous, $line['kind']));
            $row($line['kind'], $line['cells']);
            $previous = $line['kind'];
        }
        $this->output()->writeln($rule('└', '┘', $previous, null));
    }

    /**
     * The one "Next:" line for the state. Ready domains get the cutover
     * options instead (in the step tables). $rechecked: this run (or one
     * within the cooldown) asked Cloudflare to check again.
     */
    protected function writeNext(ChallengeState $state, string $siteEnv, bool $rechecked, string $pad = ''): void
    {
        $verify = "terminus gcdn:verify {$siteEnv} {$state->hostname()}";
        if ($state->active()) {
            $this->line($state->trafficSwitched()
                ? 'Next: ' . $this->paint('nothing. The domain is active and DNS points to Pantheon.', 'green')
                : "Next: switch DNS (step 3) whenever you're ready, then run: {$verify}", $pad);
            return;
        }
        if ($state->ownershipDoneCertPending()) {
            $this->line($rechecked
                ? "Next: Cloudflare is checking the step 2 record now, and usually issues the certificate within about 10 minutes. Check with: {$verify}"
                : "Next: add or fix the step 2 record so it matches the value shown, then run: {$verify}", $pad);
            return;
        }
        if ($state->candidate()) {
            $this->line($rechecked
                ? "Next: Pantheon checks the step 1 record within about 10 minutes. Check with: {$verify}"
                : "Next: add the records in steps 1 and 2, then run: {$verify}", $pad);
            $this->line($this->paint('Don\'t switch DNS yet: Pantheon hasn\'t confirmed ownership.', 'yellow'), $pad);
            return;
        }
        $this->line($rechecked
            ? "Next: Cloudflare is checking the records now. Pantheon sees the result within about 10 minutes. Check with: {$verify}"
            : "Next: add the records in steps 1 and 2, then run: {$verify}", $pad);
    }

    protected function writeZoneHold(ChallengeState $state, string $pad = ''): void
    {
        if ($state->zoneHold()) {
            $this->line($this->paint(
                'Cloudflare reports a zone hold on this domain. The domain owner has to release it in their own Cloudflare account.',
                'red'
            ), $pad);
        }
    }

    /** $note: null under a step, which names the record already. */
    protected function writeOwnershipTxt(ChallengeState $state, ?string $note, string $pad = ''): void
    {
        $txt = $state->ownershipTxt();
        if ($txt === null) {
            $this->line($this->paint('Ownership TXT record: not available yet. Try again shortly.', 'yellow'), $pad);
            return;
        }
        if ($note !== null) {
            $this->line($this->paint('TXT record — domain ownership', 'bold') . " ({$note}):", $pad);
        }
        $indent = $note !== null ? '  ' : '';
        $this->line("{$indent}Name:  {$txt['name']}", $pad);
        $this->line("{$indent}Value: {$txt['value']}", $pad);
    }

    /**
     * The DCV delegation CNAME, never the raw cert TXT (P5). It stays in
     * place for renewals. $conflicts: the note about TXT records at the same
     * name, only while the record still has to be added.
     */
    protected function writeDcv(ChallengeState $state, string $pad = '', bool $heading = true, bool $conflicts = true): void
    {
        $dcv = $state->dcv();
        if ($dcv === null) {
            $this->line($this->paint(
                'Certificate validation record: Pantheon couldn\'t determine it for this domain. Contact Pantheon support.',
                'red'
            ), $pad);
            return;
        }
        $indent = $heading ? '  ' : '';
        if ($heading) {
            $this->line(
                $this->paint('CNAME record — certificate validation (DCV delegation)', 'bold') . ', DNS only / not proxied:',
                $pad
            );
        }
        $this->line("{$indent}Name:  {$dcv['name']}", $pad);
        $this->line("{$indent}Value: {$dcv['value']}.", $pad);
        if (!$heading) {
            $this->line('DNS only / not proxied.', $pad);
        }
        if ($conflicts) {
            $this->line("{$indent}Remove any TXT records at {$dcv['name']} first; they conflict with this CNAME.", $pad);
        }
        if (!$state->active()) {
            $this->line("{$indent}Can't use a CNAME with this name? Run gcdn:verify with --cert-txt for a TXT record instead.", $pad);
        }
        $this->writeDcvRenewal(null, $dcv, "{$pad}{$indent}");
    }

    /**
     * Why the DCV CNAME should stay: required behind another proxy, extra
     * insurance without one. Worded for what gcdn:verify found in DNS
     * ($found; null when not looked up, or the lookup failed).
     */
    private function renewalAdvice(?array $found, array $dcv): string
    {
        [$verb, $do, $without] = match (true) {
            $found === null, $found !== [] && $this->foundValue($found, $dcv['value']) => ['keeping', 'keep this record in place', 'without it'],
            $found === [] => ['adding', 'add this record', 'without it'],
            default => ['changing', 'change it to the value above', 'otherwise'],
        };
        return "If you use another proxy in front of Pantheon (Cloudflare or otherwise), {$do}: {$without}, your certificate can fail to renew. "
            . "If you don't, we still recommend {$verb} it: it's extra insurance that nothing can interfere with certificate renewals.";
    }

    private function writeDcvRenewal(?array $found, array $dcv, string $pad): void
    {
        [$proxy, $insurance] = explode(' If you don\'t', $this->renewalAdvice($found, $dcv), 2);
        $this->line($this->paint($proxy, 'yellow'), $pad);
        $this->line($this->paint("If you don't{$insurance}", 'yellow'), $pad);
    }

    /**
     * The traffic records (what DNS should point to) as a lined table:
     * option 1 the CNAME, option 2 the A/AAAA records, each with its action
     * and what DNS returns today. $open: the switch can happen now. $o2o:
     * the proxied CNAME alone, the only way Cloudflare-to-Cloudflare works.
     */
    protected function writeTrafficRecords(ChallengeState $state, string $pad = '', ?string $title = null, string $color = '', bool $open = true, ?string $siteEnv = null, bool $o2o = false): void
    {
        $hostname = $state->hostname();
        $title ??= "DNS records for {$hostname}";
        $records = $state->trafficRecords();
        // Option 1 is always the CNAME, even when domains-status sends only A/AAAA.
        $target = $state->trafficTarget();
        if ($target !== null && !in_array('CNAME', array_column($records, 'type'), true)) {
            array_unshift($records, ['type' => 'CNAME', 'value' => $target, 'detected' => [], 'status' => '', 'matches' => false]);
        }
        if ($o2o) {
            $records = array_values(array_filter($records, fn($r) => $r['type'] === 'CNAME'));
        }
        if ($records === []) {
            $this->writeStepTable($title, $color, [$this->notice('Issue: Traffic record', 'Pantheon couldn\'t determine the traffic target for this domain.', 'red', 'Run "terminus gcdn:dns" for this environment, or contact Pantheon support.')], $pad);
            return;
        }

        $switched = $state->active() && $state->trafficSwitched();
        $proxiedAny = $o2o;
        foreach ($records as $record) {
            $proxiedAny = $proxiedAny || ($record['type'] !== 'CNAME' && !$record['matches'] && CloudflareIps::all($record['detected']));
        }
        $use = $o2o ? '' : 'Use one option, not both.';
        if ($open && !$switched && $siteEnv !== null) {
            // The cutover run, with the command on its own line so it copies whole.
            $use .= ($o2o ? 'After you switch DNS' : ' After you choose an option and switch DNS')
                . ', wait about 5 minutes for it to propagate (longer if the '
                . "old records had a longer TTL), then run:\nterminus gcdn:verify {$siteEnv} {$hostname}\n"
                . 'If you ran gcdn:verify in the last 5 minutes, it shows the latest poll and when a force recheck can run; '
                . 'add --recheck to force one now.';
        }
        $rows = $use !== '' ? [['span' => $use, 'color' => '']] : [];
        $group = null;
        $option = 0;
        foreach ($records as $record) {
            $thisGroup = $record['type'] === 'CNAME' ? 'cname' : 'address';
            if ($thisGroup !== $group && !$o2o) {
                $rows[] = ['span' => 'Option ' . ++$option . ($thisGroup === 'cname' ? ': CNAME' : ': A and AAAA records'), 'color' => 'blue'];
                $group = $thisGroup;
            }
            $rows[] = [
                $record['type'] === 'CNAME' && $proxiedAny && !$switched ? "CNAME\n(Proxied)" : $record['type'],
                $hostname,
                $record['value'] !== '' ? $record['value'] : '-',
                $this->trafficAction($record, $switched, $open, $proxiedAny),
            ];
        }
        $matched = array_column(array_filter($records, fn($r) => $r['matches']), 'type');
        if (in_array('CNAME', $matched, true) && in_array('A', $matched, true) && in_array('AAAA', $matched, true)) {
            $rows[] = $this->notice('Issue: Traffic records', 'Both the CNAME and the A/AAAA records are set.', 'yellow', 'Keep one option and remove the other.');
        }
        $this->writeStepTable($title, $color, $rows, $pad);
    }

    /**
     * A traffic record's action, with what DNS returns today. A proxied
     * domain's DNS shows Cloudflare's addresses, never the record behind them.
     */
    private function trafficAction(array $record, bool $switched, bool $open, bool $proxiedAny): array
    {
        $dns = implode(', ', $record['detected']);
        $proxied = !$record['matches'] && CloudflareIps::all($record['detected']);
        $has = $dns === '' ? '' : ($proxied ? " DNS shows Cloudflare's proxy addresses: {$dns}." : " DNS has: {$dns}.");
        if ($record['matches'] && $record['status'] !== 'action_required') {
            return ['None: points to Pantheon.', 'green'];
        }
        if ($switched) {
            return ['None.' . $has, 'green'];
        }
        if ($record['matches']) {
            return ['None: set. Waiting for DNS to propagate.', 'yellow'];
        }
        if (!$open) {
            return ['Not yet: after the steps above.' . $has, ''];
        }
        if ($record['type'] === 'CNAME') {
            return $proxiedAny
                ? ['Add it, Proxied. In your own Cloudflare account, delete the A and AAAA records for this name first: Cloudflare won\'t add a CNAME next to them.', 'red']
                : ['Add it.' . $has, 'red'];
        }
        if ($proxied) {
            return ['In your own Cloudflare account, use option 1 instead.' . $has, 'yellow'];
        }
        return [($dns === '' ? 'Add it.' : 'Change it to this value.') . $has, 'red'];
    }

    /**
     * Step 2 as the raw `_acme-challenge` TXT (gcdn:verify --cert-txt), for
     * DNS that can't take the CNAME: [status, color, rows].
     * $found['dcvTxt']: the TXT values at the name, looked up only when
     * there's no CNAME there.
     */
    private function certTxtStep(ChallengeState $state, array $found): array
    {
        $txt = $state->certTxt();
        if ($txt === null) {
            return ['not done', 'red', [$this->notice('Issue: TXT value', 'The TXT value isn\'t available yet: Pantheon hasn\'t received it from Cloudflare.', 'yellow', 'Try again shortly, or use the CNAME (run without --cert-txt).')]];
        }
        $action = !empty($found['dcv'])
            ? ['Delete the CNAME with this name first: the two can\'t share a name.', 'red']
            : $this->txtAction($found['dcvTxt'] ?? null, $txt['value']);
        $rows = [['TXT', $txt['name'], $txt['value'], $action]];
        if ($state->verifyMethod() === 'http') {
            $rows[] = $this->notice('Issue: Validation method', 'Cloudflare is set to validate over HTTP, so it won\'t check this TXT.', 'red', 'Run gcdn:verify with --cert-txt --method dns to switch.');
        }
        $rows[] = $this->notice('Note: TXT value', 'This value changes with every certificate order, so add it as shown today. Renewals: once traffic '
            . 'points to Pantheon, Cloudflare renews over HTTP without it (not for wildcards). If a renewal needs a TXT again, '
            . 'it has a new value: contact Pantheon Support.', 'yellow');
        return $action[1] === 'green'
            ? ['in DNS, waiting for Cloudflare to issue the certificate', 'yellow', $rows]
            : [$state->ownershipDoneCertPending() ? 'not done: Cloudflare issues the certificate once this record is in place' : 'not done: add this record', 'red', $rows];
    }

    /**
     * A domain Cloudflare stopped checking (`moved`). $rechecked: this run,
     * or one in the last minute, sent the recheck that restarts the checks.
     */
    protected function writePaused(ChallengeState $state, string $siteEnv, bool $rechecked, string $pad = ''): void
    {
        if (!$state->paused()) {
            return;
        }
        if ($state->cloudflareStatus() === 'deleted') {
            $this->line($this->paint(
                'Cloudflare marked this domain deleted (status: deleted), usually after it stayed paused for 7 days. Cloudflare doesn\'t serve it.',
                'red'
            ), $pad);
            $this->line($rechecked
                ? 'This run asked Cloudflare to restore it.'
                : "A recheck restores it: run \"terminus gcdn:verify {$siteEnv} {$state->hostname()}\".", $pad);
            $this->line('If it still shows deleted after a recheck, contact Pantheon support.', $pad);
            return;
        }
        $this->line($this->paint(
            'Cloudflare paused its checks on this domain (status: moved) because it waited a long time for the DNS switch.',
            'yellow'
        ), $pad);
        $this->line($rechecked
            ? 'This run asked Cloudflare to check again.'
            : "To restart the checks, run \"terminus gcdn:verify {$siteEnv} {$state->hostname()}\".", $pad);
        $this->line($state->ready()
            ? 'Switch DNS soon: Cloudflare pauses again after about 11 days, and removes a domain that stays paused for 7 days.'
            : 'Cloudflare removes a domain that stays paused for 7 days.', $pad);
    }

    /**
     * A Cloudflare status that adding records can't fix. No records to switch
     * to: the customer needs support.
     */
    protected function writeActionRequired(ChallengeState $state, string $siteEnv, bool $rechecked = false, string $pad = ''): void
    {
        if ($state->zoneHold()) {
            $this->writeHeldZone($state, $siteEnv, $rechecked, $pad);
            return;
        }
        $status = (string) $state->cloudflareStatus();
        $this->line('Cloudflare: ' . $this->paint("action required (status: {$status})", 'red'), $pad);
        if ($status === 'pending_deletion') {
            $reason = 'Cloudflare is removing this domain.';
        } elseif (strpos($status, 'blocked') !== false) {
            $reason = 'Cloudflare has blocked this domain.';
        } else {
            $reason = 'Cloudflare reports a status Pantheon doesn\'t expect for this domain.';
        }
        $this->line($reason, $pad);
        $this->writeCloudflareStatus($state, $pad);
        $this->line($this->paint('Don\'t switch DNS. Contact Pantheon support and include this output.', 'red'), $pad);
        $this->writeErrors($state, $pad);
    }

    /**
     * Blocked by a zone hold: the domain's zone is in the owner's own
     * Cloudflare account, and the hold stops other accounts from adding it.
     * Only the owner can release it. A recheck after that asks Cloudflare to
     * check the domain again.
     */
    private function writeHeldZone(ChallengeState $state, string $siteEnv, bool $rechecked, string $pad): void
    {
        $status = (string) $state->cloudflareStatus();
        $this->line('Cloudflare: ' . $this->paint("action required (zone hold, status: {$status})", 'red'), $pad);
        $this->line('This domain\'s zone is in its owner\'s own Cloudflare account, and that zone has a zone hold.', $pad);
        $this->line('The hold stops Pantheon\'s Cloudflare from serving the domain. Pantheon support can\'t release it.', $pad);
        $this->writeCloudflareStatus($state, $pad);
        $this->line('', $pad);
        $this->line('To fix: in the Cloudflare account that has this domain, open the domain\'s Overview page and', $pad);
        $this->line('release the zone hold. For a subdomain, it\'s enough to stop the hold from covering subdomains.', $pad);
        $this->line($rechecked
            ? 'This run asked Cloudflare to check again. While the hold is on, the domain stays blocked.'
            : "Then run: terminus gcdn:verify {$siteEnv} {$state->hostname()}", $pad);
        $this->line($this->paint('Don\'t point DNS at Pantheon until the domain is active.', 'red'), $pad);
        $this->writeErrors($state, $pad);
    }

    /** Cloudflare's own errors, word for word, under a yellow header. */
    protected function writeErrors(ChallengeState $state, string $pad = ''): void
    {
        if ($state->ownershipErrors() === [] && $state->certErrors() === []) {
            return;
        }
        $this->line($this->paint('Potential issues detected', 'bold', 'yellow'), $pad);
        foreach ($state->ownershipErrors() as $error) {
            $this->line($this->paint("Ownership: {$error}", 'yellow'), $pad);
        }
        foreach ($state->certErrors() as $error) {
            $this->line($this->paint("Certificate: {$error}", 'yellow'), $pad);
        }
    }

    /**
     * Under the status of a ready domain that Cloudflare still reports errors
     * for: Pantheon's confirmation doesn't hide them. $where: the sentence
     * that says where the errors are shown.
     */
    protected function writeConfirmedDespiteErrors(ChallengeState $state, string $where, string $pad = ''): void
    {
        if ($state->active() || ($state->ownershipErrors() === [] && $state->certErrors() === [])) {
            return;
        }
        $this->line('Pantheon has confirmed the _cf-custom-hostname TXT record has the correct value, but Cloudflare', $pad);
        $this->line('can\'t pre-validate a domain that\'s already on Cloudflare. ' . $where, $pad);
    }
}

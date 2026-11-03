<?php

namespace Pantheon\TerminusGCDN;

/**
 * Class ChallengeState.
 *
 * Reads one domain object from `GET sites/{site}/environments/{env}/domains`
 * and answers what every command needs: which state the domain is in, and
 * which records to show.
 *
 * `challenges` comes from policy-docs, through titan unchanged. Its booleans
 * are `omitempty`, so a missing key means false, and a missing string means
 * unknown. The keys policy-docs #318 (EDRT-9618) adds are absent until it
 * deploys; ready() then falls back to `verified`.
 *
 * @package Pantheon\TerminusGCDN
 */
final class ChallengeState
{
    // States, in the order they're matched: the first that applies wins.
    public const UNAVAILABLE = 'unavailable';
    public const PLATFORM = 'platform';
    public const FASTLY = 'fastly';
    public const MISSING = 'missing';
    public const ACTIVE = 'active';
    public const ACTION_REQUIRED = 'action_required';
    public const READY = 'ready';
    public const CANDIDATE = 'candidate';
    public const PENDING = 'pending';

    private object $domain;
    private ?object $challenges;

    public function __construct(object $domain)
    {
        $this->domain = $domain;
        $challenges = $domain->challenges ?? null;
        $this->challenges = is_object($challenges) ? $challenges : null;
    }

    public function hostname(): string
    {
        return (string) ($this->domain->id ?? '');
    }

    /** The validation method policy-docs sends to Cloudflare: `txt` or `http`. */
    public function verifyMethod(): string
    {
        return ($this->domain->verify_method ?? 'txt') === 'http' ? 'http' : 'txt';
    }

    public function state(): string
    {
        if ($this->cdnUnavailable()) {
            return self::UNAVAILABLE;
        }
        if ($this->isPlatform()) {
            return self::PLATFORM;
        }
        if (!$this->isCloudflare()) {
            return self::FASTLY;
        }
        if ($this->challenges === null) {
            return self::MISSING;
        }
        if ($this->active()) {
            return self::ACTIVE;
        }
        if ($this->actionRequired()) {
            return self::ACTION_REQUIRED;
        }
        if ($this->ready()) {
            return self::READY;
        }
        if ($this->candidate()) {
            return self::CANDIDATE;
        }
        return self::PENDING;
    }

    /**
     * `cdn: ""` means domains-status couldn't read policy-docs (P13). A
     * missing key keeps the old default, Fastly.
     */
    public function cdnUnavailable(): bool
    {
        return isset($this->domain->cdn) && trim((string) $this->domain->cdn) === '';
    }

    public function isCloudflare(): bool
    {
        return in_array($this->domain->cdn ?? 'fastly', ['cloudflare', 'both'], true);
    }

    /**
     * Platform domains (`*.pantheonsite.io`) are managed by Pantheon; the
     * customer can't add their records (P7).
     */
    public function isPlatform(): bool
    {
        $type = $this->domain->type ?? 'custom';
        return $type === 'platform' || (bool) preg_match('/\.pantheonsite\.io\.?$/i', $this->hostname());
    }

    public function isCustomCloudflare(): bool
    {
        return !$this->cdnUnavailable() && !$this->isPlatform() && $this->isCloudflare();
    }

    /** Cloudflare has activated the hostname and its certificate. */
    public function active(): bool
    {
        return $this->flag('verified');
    }

    /**
     * Pantheon has confirmed ownership, so the DNS records are ready. An OR,
     * not `??`: the flag is `omitempty`, and absent before #318.
     *
     * policy-docs never clears `display_dns_records`, so the flag only counts
     * while Cloudflare can still activate the domain and its certificate is
     * still active. A missing status (before #318) keeps the flag's answer.
     */
    public function ready(): bool
    {
        if ($this->active()) {
            return true;
        }
        $ssl = $this->cloudflareSslStatus();
        return $this->flag('display_dns_records')
            && in_array($this->cloudflareStatus(), [null, 'pending', 'moved', 'deleted', 'active'], true)
            && ($ssl === null || $ssl === 'active');
    }

    /**
     * Cloudflare reports a status that adding records can't fix: blocked,
     * being deleted, or one we don't know. The customer needs support.
     * `moved` and `deleted` aren't: a recheck restores them (paused()).
     */
    public function actionRequired(): bool
    {
        $status = $this->cloudflareStatus();
        return !$this->active()
            && $status !== null
            && !in_array($status, ['pending', 'moved', 'deleted', 'active', 'active_redeploying'], true);
    }

    /**
     * Cloudflare stopped checking the domain (`moved`) after it stayed
     * pending through Cloudflare's whole validation backoff, about 11 days,
     * and marks it `deleted` after more than 7 more days. An edit (the
     * recheck PATCH) restores either one, while Cloudflare still lists the
     * hostname (seen in production, 2026-10-29: deleted to active).
     */
    public function paused(): bool
    {
        return !$this->active() && in_array($this->cloudflareStatus(), ['moved', 'deleted'], true);
    }

    /**
     * The domain is served through Cloudflare today. Hidden once active, or
     * once Cloudflare has confirmed ownership.
     */
    public function candidate(): bool
    {
        return $this->flag('cloudflare_routed_o2o_candidate') && !$this->active() && !$this->ownershipDoneCertPending();
    }

    public function zoneHold(): bool
    {
        return $this->flag('zone_hold_detected');
    }

    public function cloudflareStatus(): ?string
    {
        return $this->string('cloudflare_status');
    }

    public function cloudflareSslStatus(): ?string
    {
        return $this->string('cloudflare_ssl_status');
    }

    public function observedAt(): ?string
    {
        return $this->string('cloudflare_status_observed_at');
    }

    /**
     * Ownership is active but the certificate isn't (P3). Only known once
     * #318 exposes the Cloudflare status.
     */
    public function ownershipDoneCertPending(): bool
    {
        return !$this->active()
            && $this->cloudflareStatus() === 'active'
            && $this->cloudflareSslStatus() !== null
            && $this->cloudflareSslStatus() !== 'active';
    }

    /**
     * The `_cf-custom-hostname` TXT as ['name' => ..., 'value' => ...], or
     * null when either part is empty (P17).
     */
    public function ownershipTxt(): ?array
    {
        return $this->record($this->challenges->ownership_txt ?? null);
    }

    /** @return string[] */
    public function ownershipErrors(): array
    {
        return $this->strings('ownership_errors');
    }

    /** @return string[] */
    public function certErrors(): array
    {
        return $this->strings('cert_errors');
    }

    /**
     * The serve-file token for HTTP validation, or null.
     */
    public function certHttp(): ?array
    {
        $record = $this->record($this->challenges->cert_http ?? null);
        if ($record !== null) {
            return $record;
        }
        $legacy = $this->domain->acme_preauthorization_challenges->{'http-01'} ?? null;
        if (is_object($legacy)) {
            return $this->pair($legacy->verification_key ?? null, $legacy->verification_value ?? null);
        }
        return null;
    }

    /**
     * The GCDN zone (e.g. `cfp1c`) from this domain's own recommended CNAME
     * target, or null. Never borrowed from another domain (P10).
     */
    public function zone(): ?string
    {
        $recordSets = [];
        if (!empty($this->domain->target_dns) && is_array($this->domain->target_dns)) {
            $recordSets[] = $this->domain->target_dns;
        }
        if (!empty($this->domain->dns_status_details->dns_records) && is_array($this->domain->dns_status_details->dns_records)) {
            $recordSets[] = $this->domain->dns_status_details->dns_records;
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

    public function trafficTarget(): ?string
    {
        $zone = $this->zone();
        return $zone !== null ? "fe.{$zone}.edge.pantheon.io" : null;
    }

    /**
     * The DCV delegation CNAME as ['name' => ..., 'value' => ...] (value
     * without a trailing dot), or null ("contact support").
     *
     * The API's `dcv_delegation` wins when it's well formed. Otherwise the
     * DcvZones table, permanently: hostnames verified before EDRT-9618
     * never poll again, so they never get the API value.
     */
    public function dcv(): ?array
    {
        $api = $this->record($this->challenges->dcv_delegation ?? null);
        if (
            $api !== null
            && stripos($api['name'], '_acme-challenge.') === 0
            && preg_match('/\.dcv\.cloudflare\.com\.?$/i', $api['value'])
        ) {
            return ['name' => rtrim($api['name'], '.'), 'value' => rtrim($api['value'], '.')];
        }

        $zone = $this->zone();
        $target = $zone !== null ? DcvZones::dcvTarget($this->hostname(), $zone) : null;
        if ($target === null) {
            return null;
        }
        return ['name' => '_acme-challenge.' . $this->hostname(), 'value' => $target];
    }

    /**
     * DNS records from domains-status, with whether each detected value
     * matches its target.
     *
     * @return array<int, array{type:string, value:string, detected:string, status:string, matches:bool}>
     */
    public function dnsRecords(): array
    {
        $records = [];
        foreach ($this->domain->dns_status_details->dns_records ?? [] as $record) {
            if (!is_object($record)) {
                continue;
            }
            $value = (string) ($record->value ?? $record->recommended_value ?? $record->target_value ?? '');
            $detected = (string) ($record->detected_value ?? $record->current_value ?? '');
            $records[] = [
                'type' => (string) ($record->type ?? $record->record_type ?? ''),
                'value' => $value,
                'detected' => $detected,
                'status' => (string) ($record->status ?? ''),
                'matches' => $detected !== '' && strcasecmp(trim($detected), trim($value)) === 0,
            ];
        }
        return $records;
    }

    /**
     * The traffic records, one per type (CNAME first, then A, AAAA), with
     * every value DNS returned. domains-status sends an extra row, with no
     * target, for each additional detected value.
     *
     * @return array<int, array{type:string, value:string, detected:string[], status:string, matches:bool}>
     */
    public function trafficRecords(): array
    {
        $byType = [];
        foreach ($this->dnsRecords() as $record) {
            $type = strtoupper($record['type']);
            $group = $byType[$type] ?? ['type' => $type, 'value' => '', 'detected' => [], 'status' => '', 'matches' => false];
            if ($group['value'] === '' && $record['value'] !== '') {
                $group['value'] = $record['value'];
            }
            if ($record['detected'] !== '' && !in_array($record['detected'], $group['detected'], true)) {
                $group['detected'][] = $record['detected'];
            }
            // okay wins over action_required, which wins over anything else.
            $rank = ['okay' => 2, 'action_required' => 1];
            if (($rank[$record['status']] ?? 0) > ($rank[$group['status']] ?? 0) || $group['status'] === '') {
                $group['status'] = $record['status'];
            }
            $byType[$type] = $group;
        }
        foreach ($byType as $type => $group) {
            $byType[$type]['matches'] = $group['value'] !== '' && in_array(
                strtolower(rtrim($group['value'], '.')),
                array_map(fn($v) => strtolower(rtrim(trim($v), '.')), $group['detected']),
                true
            );
        }
        $order = ['CNAME' => 0, 'A' => 1, 'AAAA' => 2];
        uksort($byType, fn($a, $b) => ($order[$a] ?? 3) <=> ($order[$b] ?? 3));
        return array_values($byType);
    }

    /**
     * The domain's traffic reaches Pantheon: DNS matches, or Cloudflare
     * activated a domain it reported as served through Cloudflare. Cloudflare
     * activates those only once the DNS target points to Pantheon's zone, and
     * a proxied domain's DNS shows Cloudflare's addresses, never the target.
     */
    public function trafficSwitched(): bool
    {
        return $this->dnsConfigured() || ($this->active() && $this->flag('cloudflare_routed_o2o_candidate'));
    }

    /**
     * The certificate's `_acme-challenge` TXT, for DNS that can't take the
     * DCV CNAME. It changes between certificate orders, so it's shown only
     * on request (--cert-txt), never by default (P5).
     */
    public function certTxt(): ?array
    {
        $txt = $this->record($this->challenges->cert_txt ?? null);
        return $txt !== null && stripos($txt['name'], '_acme-challenge.') === 0 ? $txt : null;
    }

    /** DNS points to Pantheon: the CNAME matches, or both A and AAAA do. */
    public function dnsConfigured(): bool
    {
        $matched = array_column(array_filter($this->dnsRecords(), fn($r) => $r['matches']), 'type');
        return in_array('CNAME', $matched, true) || (in_array('A', $matched, true) && in_array('AAAA', $matched, true));
    }

    private function flag(string $key): bool
    {
        return $this->challenges !== null && !empty($this->challenges->{$key});
    }

    private function string(string $key): ?string
    {
        $value = $this->challenges->{$key} ?? null;
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    /** @return string[] */
    private function strings(string $key): array
    {
        $values = $this->challenges->{$key} ?? [];
        if (!is_array($values)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $values), fn($v) => trim($v) !== ''));
    }

    private function record($record): ?array
    {
        if (!is_object($record)) {
            return null;
        }
        return $this->pair($record->key ?? null, $record->val ?? null);
    }

    private function pair($name, $value): ?array
    {
        if (!is_scalar($name) || !is_scalar($value) || trim((string) $name) === '' || trim((string) $value) === '') {
            return null;
        }
        return ['name' => (string) $name, 'value' => (string) $value];
    }
}

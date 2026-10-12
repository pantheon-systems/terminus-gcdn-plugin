<?php

namespace Pantheon\TerminusGCDN\Tests\Support;

/**
 * Names for the domain fixtures in tests/fixtures/domains/, one per state in
 * the EDRT-9618 follow-up's expected-output table.
 *
 * Shapes follow domains-status `dto.go` (`target_dns`, `dns_status_details`)
 * and policy-docs #318 (`challenges`). Replace them with sanitized real
 * responses, captured read-only from a test site, when available.
 */
final class DomainFixtures
{
    /** Challenge keys policy-docs #318 adds. Absent before it deploys. */
    public const EDRT_9618_KEYS = [
        'display_dns_records',
        'dcv_delegation',
        'cloudflare_routed_o2o_candidate',
        'zone_hold_detected',
        'ssl_active',
        'cloudflare_status',
        'cloudflare_ssl_status',
        'cloudflare_status_observed_at',
    ];

    public const PENDING = 'pending';
    public const PENDING_CERT = 'pending-cert';
    public const ACTIVE = 'active';
    public const ACTIVE_BEFORE_CUTOVER = 'active-before-cutover';
    public const READY = 'ready';
    public const CANDIDATE = 'candidate';
    public const ZONE_HOLD = 'zone-hold';
    public const PLATFORM = 'platform';
    public const FASTLY = 'fastly';
    public const NO_CHALLENGES = 'no-challenges';
    public const CDN_BLANK = 'cdn-blank';
    public const CDN_BOTH = 'cdn-both';
    public const UNKNOWN_ZONE = 'unknown-zone';
    public const CANARY_ZONE = 'canary-zone';
    public const NO_ZONE = 'no-zone';
    public const WILDCARD = 'wildcard';
    public const LEGACY_ACME = 'legacy-acme';
}

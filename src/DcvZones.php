<?php

namespace Pantheon\TerminusGCDN;

/**
 * Class DcvZones.
 *
 * Maps GCDN Cloudflare zones to their DCV delegation IDs so commands can
 * build the `_acme-challenge` CNAME target customers need for certificate
 * issuance and renewal (orange-to-orange and pre-validation flows).
 *
 * @package Pantheon\TerminusGCDN
 */
final class DcvZones
{
    /**
     * DCV delegation IDs per GCDN zone. Customer-migratable sites land in
     * one of these zones; the ID appears in the public DCV CNAME target,
     * so these values are not secret.
     *
     * Interim approach: this map must be updated (and the plugin released)
     * if a zone is added or a DCV UUID changes. EDRT-9378 tracks serving
     * the DCV target from the domains API instead, at which point this
     * class can be deleted.
     */
    public const DCV_IDS = [
        'cfp1c' => 'dc5ecddca9c0e249',
        'cfp2c' => 'b0ac3dd9e8558525',
    ];

    /**
     * Extracts the GCDN zone name (e.g. `cfp1c`) from a recommended CNAME
     * target like `fe.cfp1c.edge.pantheon.io`, or null if it doesn't match.
     */
    public static function zoneFromCnameTarget(string $cnameTarget): ?string
    {
        if (preg_match('/\bfe\.(cfp[a-z0-9-]+)\.edge\.pantheon\.io\.?$/i', trim($cnameTarget), $matches)) {
            return strtolower($matches[1]);
        }
        return null;
    }

    /**
     * Builds the DCV delegation CNAME target for a hostname on a zone, or
     * null if the zone has no known DCV ID.
     */
    public static function dcvTarget(string $hostname, string $zone): ?string
    {
        $dcvId = self::DCV_IDS[strtolower($zone)] ?? null;
        if ($dcvId === null) {
            return null;
        }
        return sprintf('%s.%s.dcv.cloudflare.com', $hostname, $dcvId);
    }
}

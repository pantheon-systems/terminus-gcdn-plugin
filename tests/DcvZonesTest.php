<?php

namespace Pantheon\TerminusGCDN\Tests;

use Pantheon\TerminusGCDN\DcvZones;
use PHPUnit\Framework\TestCase;

final class DcvZonesTest extends TestCase
{
    public function testZoneFromCnameTarget(): void
    {
        $this->assertSame('cfp1c', DcvZones::zoneFromCnameTarget('fe.cfp1c.edge.pantheon.io'));
        $this->assertSame('cfp2c', DcvZones::zoneFromCnameTarget(' FE.CFP2C.edge.pantheon.io. '));
        $this->assertSame('cfp-us-central1-ch-1', DcvZones::zoneFromCnameTarget('fe.cfp-us-central1-ch-1.edge.pantheon.io'));
        $this->assertNull(DcvZones::zoneFromCnameTarget('104.18.1.1'));
        $this->assertNull(DcvZones::zoneFromCnameTarget('live-my-site.pantheonsite.io'));
    }

    public function testDcvTargetForKnownZones(): void
    {
        $this->assertSame('www.example.com.dc5ecddca9c0e249.dcv.cloudflare.com', DcvZones::dcvTarget('www.example.com', 'cfp1c'));
        $this->assertSame('www.example.com.b0ac3dd9e8558525.dcv.cloudflare.com', DcvZones::dcvTarget('www.example.com', 'CFP2C'));
    }

    public function testUnknownZoneHasNoTarget(): void
    {
        $this->assertNull(DcvZones::dcvTarget('www.example.com', 'cfp-us-central1-ch-1'));
        $this->assertNull(DcvZones::dcvTarget('www.example.com', 'cfp0c'));
    }

    /** P11: a wildcard passes through unchanged, giving an invalid name. */
    public function testP11WildcardPassesThrough(): void
    {
        $this->assertSame('*.example.com.dc5ecddca9c0e249.dcv.cloudflare.com', DcvZones::dcvTarget('*.example.com', 'cfp1c'));
    }
}

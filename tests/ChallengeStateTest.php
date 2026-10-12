<?php

namespace Pantheon\TerminusGCDN\Tests;

use Pantheon\TerminusGCDN\ChallengeState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChallengeStateTest extends TestCase
{
    private function state(?array $challenges = null, array $domain = []): ChallengeState
    {
        $data = $domain + [
            'id' => 'www.example.com',
            'type' => 'custom',
            'cdn' => 'cloudflare',
            'target_dns' => [['type' => 'CNAME', 'target_value' => 'fe.cfp1c.edge.pantheon.io']],
        ];
        if ($challenges !== null) {
            $data['challenges'] = $challenges;
        }
        return new ChallengeState(json_decode(json_encode($data)));
    }

    public static function states(): array
    {
        return [
            'cdn blank' => [['verified' => true], ['cdn' => ''], ChallengeState::UNAVAILABLE],
            'cdn blank wins over platform' => [[], ['cdn' => ' ', 'type' => 'platform'], ChallengeState::UNAVAILABLE],
            'platform by type' => [['verified' => false], ['type' => 'platform'], ChallengeState::PLATFORM],
            'platform by name' => [[], ['id' => 'live-x.pantheonsite.io'], ChallengeState::PLATFORM],
            'fastly' => [null, ['cdn' => 'fastly'], ChallengeState::FASTLY],
            'challenges missing' => [null, [], ChallengeState::MISSING],
            'active' => [['verified' => true, 'display_dns_records' => true], [], ChallengeState::ACTIVE],
            'active wins over candidate' => [['verified' => true, 'cloudflare_routed_o2o_candidate' => true], [], ChallengeState::ACTIVE],
            'ready' => [['verified' => false, 'display_dns_records' => true, 'cloudflare_routed_o2o_candidate' => true], [], ChallengeState::READY],
            'candidate' => [['verified' => false, 'cloudflare_routed_o2o_candidate' => true], [], ChallengeState::CANDIDATE],
            'pending' => [['verified' => false], [], ChallengeState::PENDING],
            'pending, keys absent' => [['ready' => true, 'verified' => false], [], ChallengeState::PENDING],
            'cdn both' => [['verified' => false], ['cdn' => 'both'], ChallengeState::PENDING],
        ];
    }

    #[DataProvider('states')]
    public function testState(?array $challenges, array $domain, string $expected): void
    {
        $this->assertSame($expected, $this->state($challenges, $domain)->state());
    }

    public function testMissingCdnKeyKeepsTheOldFastlyDefault(): void
    {
        $state = new ChallengeState((object) ['id' => 'www.example.com', 'type' => 'custom', 'challenges' => (object) []]);
        $this->assertSame(ChallengeState::FASTLY, $state->state());
    }

    public function testReadyIsAnOrNotACoalesce(): void
    {
        $this->assertTrue($this->state(['verified' => true, 'display_dns_records' => false])->ready());
        $this->assertTrue($this->state(['verified' => false, 'display_dns_records' => true])->ready());
        $this->assertFalse($this->state(['verified' => false])->ready());
    }

    public function testCandidateIsHiddenOnceActive(): void
    {
        $this->assertFalse($this->state(['verified' => true, 'cloudflare_routed_o2o_candidate' => true])->candidate());
    }

    public function testDcvPrefersAWellFormedApiValue(): void
    {
        $dcv = $this->state(['dcv_delegation' => ['key' => '_acme-challenge.www.example.com', 'val' => 'www.example.com.abc.dcv.cloudflare.com.']])->dcv();
        $this->assertSame(['name' => '_acme-challenge.www.example.com', 'value' => 'www.example.com.abc.dcv.cloudflare.com'], $dcv);
    }

    public function testDcvFallsBackToTheTable(): void
    {
        $expected = ['name' => '_acme-challenge.www.example.com', 'value' => 'www.example.com.dc5ecddca9c0e249.dcv.cloudflare.com'];
        $this->assertSame($expected, $this->state([])->dcv(), 'absent');
        $this->assertSame($expected, $this->state(['dcv_delegation' => ['key' => 'www.example.com', 'val' => 'x.dcv.cloudflare.com']])->dcv(), 'bad key');
        $this->assertSame($expected, $this->state(['dcv_delegation' => ['key' => '_acme-challenge.www.example.com', 'val' => 'x.example.net']])->dcv(), 'bad value');
        $this->assertSame($expected, $this->state(['dcv_delegation' => ['key' => '', 'val' => '']])->dcv(), 'empty');
    }

    public function testDcvIsNullForAnUnknownZoneOrNoZone(): void
    {
        $this->assertNull($this->state([], ['target_dns' => [['type' => 'CNAME', 'target_value' => 'fe.cfp9z.edge.pantheon.io']]])->dcv());
        $this->assertNull($this->state([], ['target_dns' => []])->dcv());
    }

    public function testWildcardUsesOnlyTheApiValue(): void
    {
        $this->assertNull($this->state([], ['id' => '*.example.com'])->dcv());
        $api = ['key' => '_acme-challenge.example.com', 'val' => 'example.com.abc.dcv.cloudflare.com'];
        $this->assertSame('example.com.abc.dcv.cloudflare.com', $this->state(['dcv_delegation' => $api], ['id' => '*.example.com'])->dcv()['value']);
    }

    public function testZoneComesFromTheDomainsOwnRecords(): void
    {
        $this->assertSame('cfp2c', $this->state([], ['target_dns' => [], 'dns_status_details' => ['dns_records' => [['type' => 'CNAME', 'target_value' => 'fe.cfp2c.edge.pantheon.io']]]])->zone());
        $this->assertSame('fe.cfp1c.edge.pantheon.io', $this->state([])->trafficTarget());
    }

    public function testOwnershipTxtNeedsBothParts(): void
    {
        $this->assertSame(['name' => '_cf-custom-hostname.www.example.com', 'value' => 'v'], $this->state(['ownership_txt' => ['key' => '_cf-custom-hostname.www.example.com', 'val' => 'v']])->ownershipTxt());
        $this->assertNull($this->state(['ownership_txt' => ['key' => '_cf-custom-hostname.www.example.com', 'val' => '']])->ownershipTxt());
        $this->assertNull($this->state([])->ownershipTxt());
    }

    public function testErrorsDropBlanks(): void
    {
        $state = $this->state(['ownership_errors' => ['', 'a'], 'cert_errors' => ['b', ' ']]);
        $this->assertSame(['a'], $state->ownershipErrors());
        $this->assertSame(['b'], $state->certErrors());
        $this->assertSame([], $this->state(['ownership_errors' => 'not a list'])->ownershipErrors());
    }

    public function testOwnershipDoneCertPendingNeedsTheStatus(): void
    {
        $this->assertTrue($this->state(['cloudflare_status' => 'active', 'cloudflare_ssl_status' => 'pending_validation'])->ownershipDoneCertPending());
        $this->assertFalse($this->state(['cloudflare_status' => 'active', 'cloudflare_ssl_status' => 'active'])->ownershipDoneCertPending());
        $this->assertFalse($this->state(['cloudflare_status' => 'pending', 'cloudflare_ssl_status' => 'pending_validation'])->ownershipDoneCertPending());
        $this->assertFalse($this->state([])->ownershipDoneCertPending());
    }

    public function testVerifyMethodDefaultsToTxt(): void
    {
        $this->assertSame('txt', $this->state([])->verifyMethod());
        $this->assertSame('http', $this->state([], ['verify_method' => 'http'])->verifyMethod());
        $this->assertSame('txt', $this->state([], ['verify_method' => 'bogus'])->verifyMethod());
    }

    public function testDnsConfigured(): void
    {
        $records = fn(array $matching) => ['dns_status_details' => ['dns_records' => array_map(
            fn($type) => ['type' => $type, 'target_value' => 'x', 'detected_value' => in_array($type, $matching, true) ? 'X' : 'y'],
            ['CNAME', 'A', 'AAAA']
        )]];
        $this->assertTrue($this->state([], $records(['CNAME']))->dnsConfigured());
        $this->assertTrue($this->state([], $records(['A', 'AAAA']))->dnsConfigured());
        $this->assertFalse($this->state([], $records(['A']))->dnsConfigured());
    }
}

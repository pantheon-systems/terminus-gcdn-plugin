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
            'ready, moved' => [['display_dns_records' => true, 'cloudflare_status' => 'moved', 'cloudflare_ssl_status' => 'active'], [], ChallengeState::READY],
            'ready, moved, cert lost' => [['display_dns_records' => true, 'cloudflare_routed_o2o_candidate' => true, 'cloudflare_status' => 'moved', 'cloudflare_ssl_status' => 'pending_validation'], [], ChallengeState::CANDIDATE],
            'ready, blocked' => [['display_dns_records' => true, 'cloudflare_status' => 'blocked'], [], ChallengeState::ACTION_REQUIRED],
            'ready, deleted' => [['display_dns_records' => true, 'cloudflare_status' => 'deleted'], [], ChallengeState::READY],
            'pending, blocked' => [['cloudflare_status' => 'pending_blocked'], [], ChallengeState::ACTION_REQUIRED],
            'active wins over blocked' => [['verified' => true, 'cloudflare_status' => 'blocked'], [], ChallengeState::ACTIVE],
            'ownership done, cert pending, not candidate' => [['display_dns_records' => true, 'cloudflare_routed_o2o_candidate' => true, 'cloudflare_status' => 'active', 'cloudflare_ssl_status' => 'pending_validation'], [], ChallengeState::PENDING],
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

    /**
     * policy-docs never clears display_dns_records, so ready() follows the
     * Cloudflare status: every status in cloudflare-go v7.11.0, plus an
     * unknown one and none (before #318).
     */
    public static function statuses(): array
    {
        return [
            'none' => [null, true, false, false],
            'pending' => ['pending', true, false, false],
            'moved' => ['moved', true, false, true],
            'active' => ['active', true, false, false],
            'active_redeploying' => ['active_redeploying', false, false, false],
            'pending_deletion' => ['pending_deletion', false, true, false],
            'deleted' => ['deleted', true, false, true],
            'blocked' => ['blocked', false, true, false],
            'pending_blocked' => ['pending_blocked', false, true, false],
            'test_blocked' => ['test_blocked', false, true, false],
            'pending_migration' => ['pending_migration', false, true, false],
            'pending_provisioned' => ['pending_provisioned', false, true, false],
            'provisioned' => ['provisioned', false, true, false],
            'test_pending' => ['test_pending', false, true, false],
            'test_active' => ['test_active', false, true, false],
            'test_active_apex' => ['test_active_apex', false, true, false],
            'test_failed' => ['test_failed', false, true, false],
            'unknown' => ['something_new', false, true, false],
        ];
    }

    #[DataProvider('statuses')]
    public function testDisplayDnsRecordsFollowsTheStatus(?string $status, bool $ready, bool $actionRequired, bool $paused): void
    {
        $challenges = ['display_dns_records' => true, 'cloudflare_ssl_status' => 'active'];
        if ($status !== null) {
            $challenges['cloudflare_status'] = $status;
        }
        $state = $this->state($challenges);
        $this->assertSame($ready, $state->ready(), 'ready');
        $this->assertSame($actionRequired, $state->actionRequired(), 'actionRequired');
        $this->assertSame($paused, $state->paused(), 'paused');
    }

    public function testReadyNeedsTheCertificate(): void
    {
        $this->assertTrue($this->state(['display_dns_records' => true, 'cloudflare_status' => 'pending'])->ready(), 'ssl status absent');
        $this->assertFalse($this->state(['display_dns_records' => true, 'cloudflare_status' => 'moved', 'cloudflare_ssl_status' => 'pending_validation'])->ready());
        $this->assertTrue($this->state(['verified' => true, 'cloudflare_ssl_status' => 'pending_validation'])->ready(), 'active wins');
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

    public function testTrafficRecordsGroupExtraDetectedRows(): void
    {
        // domains-status as frankensteingym.com returned it (2026-11-03): an extra row, with no
        // target, for each additional detected address.
        $row = fn($type, $value, $detected) => ['type' => $type, 'value' => $value, 'detected_value' => $detected, 'status' => 'action_required'];
        $state = $this->state([], ['dns_status_details' => ['dns_records' => [
            $row('A', '', '104.21.39.242'),
            $row('A', '185.178.196.1', '172.67.172.41'),
            $row('AAAA', '', '2606:4700:3032::6815:27f2'),
            $row('AAAA', '2a0a:6c80::1', '2606:4700:3033::ac43:ac29'),
            ['type' => 'CNAME', 'value' => 'fe.cfp1c.edge.pantheon.io'],
        ]]]);
        $records = $state->trafficRecords();
        $this->assertSame(['CNAME', 'A', 'AAAA'], array_column($records, 'type'));
        $this->assertSame('185.178.196.1', $records[1]['value']);
        $this->assertSame(['104.21.39.242', '172.67.172.41'], $records[1]['detected']);
        $this->assertSame('action_required', $records[1]['status']);
        $this->assertFalse($records[1]['matches']);
    }

    public function testTrafficSwitched(): void
    {
        $proxied = ['dns_status_details' => ['dns_records' => [['type' => 'A', 'value' => '185.178.196.1', 'detected_value' => '104.21.39.242']]]];
        // Cloudflare activates a Cloudflare-routed domain only once it points to Pantheon's zone.
        $this->assertTrue($this->state(['verified' => true, 'cloudflare_routed_o2o_candidate' => true], $proxied)->trafficSwitched());
        $this->assertFalse($this->state(['verified' => true], $proxied)->trafficSwitched());
        $this->assertFalse($this->state(['display_dns_records' => true, 'cloudflare_routed_o2o_candidate' => true], $proxied)->trafficSwitched());
    }

    public function testCertTxtNeedsTheAcmeName(): void
    {
        $this->assertSame(
            ['name' => '_acme-challenge.www.example.com', 'value' => 'tok'],
            $this->state(['cert_txt' => ['key' => '_acme-challenge.www.example.com', 'val' => 'tok']])->certTxt()
        );
        $this->assertNull($this->state(['cert_txt' => ['key' => 'www.example.com', 'val' => 'tok']])->certTxt());
        $this->assertNull($this->state([])->certTxt());
    }
}

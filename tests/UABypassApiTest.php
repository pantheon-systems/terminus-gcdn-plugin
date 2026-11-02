<?php

namespace Pantheon\TerminusGCDN\Tests;

use Consolidation\Config\Config;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Request\Request;
use Pantheon\Terminus\Request\RequestOperationResult;
use Pantheon\Terminus\Session\Session;
use Pantheon\TerminusGCDN\UABypassApi;
use PHPUnit\Framework\TestCase;

final class UABypassApiTest extends TestCase
{
    private const SITE = '11111111-2222-3333-4444-555555555555';
    private const BASE = 'https://terminus.pantheon.io:443/ua-bypass/v1/sites/11111111-2222-3333-4444-555555555555'
        . '/environments/live/hostnames/www.example.com';

    /** @var array{0: string, 1: array}|null */
    private ?array $sent = null;

    private function api(?\Throwable $throw = null): UABypassApi
    {
        $session = $this->createMock(Session::class);
        $session->method('get')->with('session')->willReturn('session-token');

        $request = $this->createMock(Request::class);
        $request->method('getConfig')->willReturn(
            new Config(['protocol' => 'https', 'port' => 443, 'host' => 'terminus.pantheon.io'])
        );
        $request->method('session')->willReturn($session);
        $request->method('request')->willReturnCallback(
            function (string $url, array $options) use ($throw) {
                $this->sent = [$url, $options];
                if ($throw !== null) {
                    throw $throw;
                }
                return new RequestOperationResult([
                    'data' => (object) ['entries' => []],
                    'headers' => [],
                    'status_code' => 200,
                    'status_code_reason' => 'OK',
                ]);
            }
        );

        return new UABypassApi($request);
    }

    public function testListIsAGetWithTheSession(): void
    {
        $this->api()->list(self::SITE, 'live', 'www.example.com');

        [$url, $options] = $this->sent;
        $this->assertSame(self::BASE, $url);
        $this->assertSame('get', $options['method']);
        $this->assertArrayNotHasKey('form_params', $options);
        $this->assertSame('session-token', $options['headers']['Authorization']);
        $this->assertSame('application/json', $options['headers']['Accept']);
    }

    public function testAddSendsTheEntryInTheBodyOnly(): void
    {
        $this->api()->change(self::SITE, 'live', 'www.example.com', 'add', 'ExampleBot/1.0 (+https://x.example)');

        [$url, $options] = $this->sent;
        $this->assertSame(self::BASE, $url);
        $this->assertSame('post', $options['method']);
        $this->assertSame(['action' => 'add', 'entry' => 'ExampleBot/1.0 (+https://x.example)'], $options['form_params']);
        $this->assertStringNotContainsString('ExampleBot', $url);
    }

    public function testAcknowledgementIsSentOnlyWhenOptedIn(): void
    {
        $this->api()->change(self::SITE, 'live', 'www.example.com', 'add', 'Mozilla/5.0', true);
        $this->assertTrue($this->sent[1]['form_params']['acknowledge_browser_ua']);
    }

    public function testAcknowledgementIsNeverSentOnRemove(): void
    {
        $this->api()->change(self::SITE, 'live', 'www.example.com', 'remove', 'Mozilla/5.0', true);
        $this->assertSame(['action' => 'remove', 'entry' => 'Mozilla/5.0'], $this->sent[1]['form_params']);
    }

    public function testPathSegmentsAreEncoded(): void
    {
        $this->api()->list(self::SITE, 'live', 'a/b');
        $this->assertStringEndsWith('/hostnames/a%2Fb', $this->sent[0]);
    }

    public function testRetryExhaustionBecomesTheUnavailableMessage(): void
    {
        $this->expectException(TerminusException::class);
        $this->expectExceptionMessage('did not respond successfully after several attempts');

        $this->api(new TerminusException('HTTP request has failed with error "Maximum retry attempts reached".'))
            ->change(self::SITE, 'live', 'www.example.com', 'add', 'Bot/1.0');
    }

    public function testOtherExceptionsPassThrough(): void
    {
        $this->expectException(TerminusException::class);
        $this->expectExceptionMessage('something else');

        $this->api(new TerminusException('something else'))->list(self::SITE, 'live', 'www.example.com');
    }
}

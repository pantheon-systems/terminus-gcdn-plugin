<?php

namespace Pantheon\TerminusGCDN\Tests\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusGCDN\BotBypassApi;
use Pantheon\TerminusGCDN\Commands\BotBypassCommand;
use Pantheon\TerminusGCDN\Tests\Support\CommandHarness;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * gcdn:bot-bypass at the command level. BotBypassResponseTest covers the
 * message and row helpers; guidance wording is left to it (PR #16 changes it).
 */
final class BotBypassCommandTest extends CommandHarness
{
    private const PATH = '#/bot-bypass/v1/sites/[^/]+/token$#';

    private function body(bool $withNext = true): object
    {
        $body = [
            'token' => 'TKN-CUR-9f3a',
            'valid_from' => '2026-09-01T00:00:00Z',
            'expires_at' => '2027-03-01T00:00:00Z',
            'header_name' => 'x-pantheon-bot-bypass',
        ];
        if ($withNext) {
            $body['next'] = ['token' => 'TKN-NXT-77b1', 'valid_from' => '2026-12-01T00:00:00Z', 'expires_at' => '2027-06-01T00:00:00Z'];
        }
        return json_decode(json_encode($body));
    }

    public function testTwoTokensUseAbsoluteUrlAndRawSessionHeader(): void
    {
        $cmd = new BotBypassCommand();
        $this->wire($cmd);
        $this->on('GET', self::PATH, $this->apiResult(200, $this->body()));

        $rows = $cmd->botBypass('my-site.dev')->getArrayCopy();

        $this->assertSame(['SITES.GET', 'my-site', []], $this->calls[0], 'the env suffix is dropped');
        [, $url, $options] = $this->calls[1];
        $this->assertSame('https://terminus.pantheon.io:443/bot-bypass/v1/sites/' . self::SITE_ID . '/token', $url);
        $this->assertSame('SESSION-TOKEN', $options['headers']['Authorization']);
        $this->assertSame(['current', 'next'], array_column($rows, 'role'));
        $this->assertSame(['role', 'token', 'valid_from', 'expires_at', 'header_name'], array_keys($rows[0]));
        $this->assertSame([], $this->writes());
        $this->assertStringNotContainsString('TKN-', $this->logger->text(), 'tokens are never logged');
    }

    public function testOneToken(): void
    {
        $cmd = new BotBypassCommand();
        $this->wire($cmd);
        $this->on('GET', self::PATH, $this->apiResult(200, $this->body(false)));
        $rows = $cmd->botBypass('my-site')->getArrayCopy();
        $this->assertSame(['current'], array_column($rows, 'role'));
        $this->assertStringNotContainsString('TKN-', $this->logger->text());
    }

    public function testSandboxHostIsRewritten(): void
    {
        $cmd = new BotBypassCommand();
        $this->wire($cmd, ['host' => 'hermes.sandbox-foo.svc.pantheon.io']);
        $this->on('GET', self::PATH, $this->apiResult(200, $this->body()));
        $cmd->botBypass('my-site');
        $this->assertStringStartsWith('https://pantheonapi.sandbox-foo.svc.pantheon.io:443/bot-bypass/v1/', $this->calls[1][1]);
    }

    public static function errorStatuses(): array
    {
        return [
            '401' => [401, 'auth:login'],
            '403' => [403, 'You do not have access to my-site.'],
            '404' => [404, 'not available yet'],
            '501' => [501, 'HTTP 501'],
        ];
    }

    #[DataProvider('errorStatuses')]
    public function testErrorStatusesNeverEchoTheBody(int $status, string $fragment): void
    {
        $cmd = new BotBypassCommand();
        $this->wire($cmd);
        $this->on('GET', self::PATH, $this->apiResult($status, (object) ['message' => 'secret body']));
        try {
            $cmd->botBypass('my-site');
            $this->fail('expected exception');
        } catch (TerminusException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
            $this->assertStringNotContainsString('secret body', $e->getMessage());
        }
    }

    public function testRetriesExhaustedMapToUnavailable(): void
    {
        $cmd = new BotBypassCommand();
        $this->wire($cmd);
        $this->on('GET', self::PATH, function () {
            throw new TerminusException('HTTP request has failed with error "Maximum retry attempts reached".');
        });
        $this->expectExceptionMessage(BotBypassApi::UNAVAILABLE_MESSAGE);
        $cmd->botBypass('my-site');
    }

    public function testOtherExceptionsPassThrough(): void
    {
        $cmd = new BotBypassCommand();
        $this->wire($cmd);
        $this->on('GET', self::PATH, function () {
            throw new TerminusException('Something else');
        });
        $this->expectExceptionMessage('Something else');
        $cmd->botBypass('my-site');
    }

    public function testHtmlBodyIsMalformed(): void
    {
        $cmd = new BotBypassCommand();
        $this->wire($cmd);
        $this->on('GET', self::PATH, $this->apiResult(200, '<html>proxy</html>'));
        $this->expectExceptionMessage('Unexpected response');
        $cmd->botBypass('my-site');
    }

    public function testUnknownSiteFailsBeforeAnyRequest(): void
    {
        $cmd = new BotBypassCommand();
        $this->wire($cmd);
        try {
            $cmd->botBypass('not-my-site');
            $this->fail('expected exception');
        } catch (TerminusException $e) {
            $this->assertCount(1, $this->calls, 'only the site lookup');
        }
    }

    /** BB2: a 404 always says "still rolling out". Separate ticket; coordinate with PR #16. */
    public function testBB2NotFoundAlwaysSaysRollingOut(): void
    {
        $this->markTestIncomplete('BB2 (separate ticket): distinguish a missing site from a rollout gap, and fix the README retry claim.');
    }
}

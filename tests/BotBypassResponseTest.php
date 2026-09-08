<?php

namespace Pantheon\TerminusGCDN\Tests;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusGCDN\BotBypassResponse;
use PHPUnit\Framework\TestCase;

final class BotBypassResponseTest extends TestCase
{
    private function decode(array $body): object
    {
        return json_decode(json_encode($body), false);
    }

    private function twoTokenBody(): array
    {
        return [
            'token' => 'current-token-value',
            'bucket' => '2026-09',
            'valid_from' => '2026-09-01T00:00:00.000Z',
            'expires_at' => '2027-03-01T00:00:00.000Z',
            'next' => [
                'token' => 'next-token-value',
                'bucket' => '2026-12',
                'valid_from' => '2026-12-01T00:00:00.000Z',
                'expires_at' => '2027-06-01T00:00:00.000Z',
            ],
            'header_name' => 'x-pantheon-bot-bypass',
        ];
    }

    public function testRowsForTwoTokens(): void
    {
        $rows = BotBypassResponse::rows($this->decode($this->twoTokenBody()));

        $this->assertCount(2, $rows);
        $this->assertSame([
            'role' => 'current',
            'token' => 'current-token-value',
            'valid_from' => '2026-09-01T00:00:00.000Z',
            'expires_at' => '2027-03-01T00:00:00.000Z',
            'header_name' => 'x-pantheon-bot-bypass',
        ], $rows[0]);
        $this->assertSame([
            'role' => 'next',
            'token' => 'next-token-value',
            'valid_from' => '2026-12-01T00:00:00.000Z',
            'expires_at' => '2027-06-01T00:00:00.000Z',
            'header_name' => 'x-pantheon-bot-bypass',
        ], $rows[1]);
    }

    public function testRowsWithoutNextYieldsOneRow(): void
    {
        $body = $this->twoTokenBody();
        unset($body['next']);

        $rows = BotBypassResponse::rows($this->decode($body));

        $this->assertCount(1, $rows);
        $this->assertSame('current', $rows[0]['role']);
    }

    public function testRowsToleratesMissingWindowFields(): void
    {
        $body = $this->twoTokenBody();
        unset($body['valid_from'], $body['next']['valid_from']);

        $rows = BotBypassResponse::rows($this->decode($body));

        $this->assertSame('', $rows[0]['valid_from']);
        $this->assertSame('', $rows[1]['valid_from']);
    }

    public function testRowsRejectsMissingToken(): void
    {
        $body = $this->twoTokenBody();
        unset($body['token']);

        $this->expectException(TerminusException::class);
        BotBypassResponse::rows($this->decode($body));
    }

    public function testRowsRejectsMissingHeaderName(): void
    {
        $body = $this->twoTokenBody();
        unset($body['header_name']);

        $this->expectException(TerminusException::class);
        BotBypassResponse::rows($this->decode($body));
    }

    public function testRowsRejectsNonObjectBody(): void
    {
        $this->expectException(TerminusException::class);
        BotBypassResponse::rows('404 page not found');
    }

    public function testErrorMessages(): void
    {
        $this->assertNull(BotBypassResponse::errorMessage(200, 'my-site'));
        $this->assertStringContainsString('auth:login', BotBypassResponse::errorMessage(401, 'my-site'));
        $this->assertStringContainsString('my-site', BotBypassResponse::errorMessage(403, 'my-site'));
        $this->assertStringContainsString('not available yet', BotBypassResponse::errorMessage(404, 'my-site'));
        $this->assertStringContainsString('HTTP 500', BotBypassResponse::errorMessage(500, 'my-site'));
    }

    public function testGuidanceWithNextToken(): void
    {
        $rows = BotBypassResponse::rows($this->decode($this->twoTokenBody()));
        $lines = BotBypassResponse::guidance($rows);

        $this->assertStringContainsString('x-pantheon-bot-bypass', $lines[0]);
        $this->assertStringContainsString('2026-12-01', $lines[1]);
        $this->assertStringContainsString('2027-03-01', $lines[1]);
        $this->assertStringNotContainsString('current-token-value', implode("\n", $lines));
        $this->assertStringNotContainsString('next-token-value', implode("\n", $lines));
    }

    public function testGuidanceWithoutNextToken(): void
    {
        $body = $this->twoTokenBody();
        unset($body['next']);
        $lines = BotBypassResponse::guidance(BotBypassResponse::rows($this->decode($body)));

        $this->assertStringContainsString('not available yet', $lines[1]);
    }
}

<?php

namespace Pantheon\TerminusGCDN\Tests\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusGCDN\Commands\UpdatePluginCommand;
use Pantheon\TerminusGCDN\Tests\Support\ArrayLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * gcdn:update, pinned to the behavior at 0.4.0. GitHub and the
 * `terminus self:plugin:update` process are replaced through the command's
 * httpGet() and runProcess() seams, so nothing leaves the machine.
 */
final class UpdatePluginCommandTest extends TestCase
{
    private const RELEASES = 'https://api.github.com/repos/pantheon-systems/terminus-gcdn-plugin/releases/latest';
    private const TAGS = 'https://api.github.com/repos/pantheon-systems/terminus-gcdn-plugin/tags?per_page=1';

    private BufferedOutput $out;
    private ArrayLogger $logger;

    /**
     * @param array<string, string|false> $responses URL => body (false = failure)
     */
    private function command(array $responses, int $processExit = 0): object
    {
        $cmd = new class ($responses, $processExit) extends UpdatePluginCommand {
            public array $fetched = [];
            public array $ran = [];

            public function __construct(private array $responses, private int $processExit)
            {
            }

            protected function httpGet(string $url)
            {
                $this->fetched[] = $url;
                return $this->responses[$url] ?? false;
            }

            protected function runProcess(string $command): int
            {
                $this->ran[] = $command;
                return $this->processExit;
            }
        };
        $this->out = new BufferedOutput();
        $this->logger = new ArrayLogger();
        $cmd->setLogger($this->logger);
        $cmd->setInput(new ArrayInput([]));
        $cmd->setOutput($this->out);
        return $cmd;
    }

    private function plain(): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $this->out->fetch());
    }

    private static function release(string $tag): string
    {
        return json_encode(['tag_name' => $tag]);
    }

    public function testVersionIsSemver(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', UpdatePluginCommand::VERSION);
    }

    public function testUpToDateRunsNothing(): void
    {
        $cmd = $this->command([self::RELEASES => self::release(UpdatePluginCommand::VERSION)]);
        $this->assertNull($cmd->update());
        $this->assertSame([], $cmd->ran);
        $this->assertStringContainsString('is up to date (v' . UpdatePluginCommand::VERSION . ')', $this->plain());
    }

    public function testNewerReleaseRunsSelfPluginUpdate(): void
    {
        $cmd = $this->command([self::RELEASES => self::release('99.0.0')]);
        $cmd->update();
        $this->assertSame(['terminus self:plugin:update pantheon-systems/terminus-gcdn-plugin'], $cmd->ran);
        $this->assertStringContainsString('Updated to v99.0.0.', $this->plain());
    }

    public function testLeadingVIsIgnored(): void
    {
        $cmd = $this->command([self::RELEASES => self::release('v99.0.0')]);
        $cmd->update();
        $this->assertStringContainsString('→ v99.0.0', $this->plain());
    }

    public function testFallsBackToTagsWhenReleasesFail(): void
    {
        $cmd = $this->command([self::TAGS => json_encode([['name' => '99.0.0']])]);
        $cmd->update();
        $this->assertSame([self::RELEASES, self::TAGS], $cmd->fetched);
        $this->assertCount(1, $cmd->ran);
    }

    public function testBothSourcesFailingThrows(): void
    {
        $cmd = $this->command([]);
        $this->expectException(TerminusException::class);
        $this->expectExceptionMessage('Could not check for updates.');
        $cmd->update();
    }

    /** G2: a failed update prints manual steps but exits 0. */
    public function testG2FailedUpdateExitsZero(): void
    {
        $cmd = $this->command([self::RELEASES => self::release('99.0.0')], 1);
        $this->assertNull($cmd->update());
        $this->assertStringContainsString('Automatic update failed.', $this->plain());
    }

    /** G2: any exit 0 from self:plugin:update is reported as an upgrade. Separate ticket. */
    public function testG2SuccessIsNotConfirmed(): void
    {
        $this->markTestIncomplete('G2 (separate ticket): confirm the installed version changed before saying "Updated".');
    }

    /** G3: a GitHub 403 rate limit reads as a network error. Separate ticket. */
    public function testG3RateLimitIsReportedAsNetworkError(): void
    {
        $cmd = $this->command([]);
        try {
            $cmd->update();
            $this->fail('expected exception');
        } catch (TerminusException $e) {
            $this->assertStringContainsString('Verify your network connection', $e->getMessage());
        }
        $this->markTestIncomplete('G3 (separate ticket): report GitHub rate limits as such.');
    }
}

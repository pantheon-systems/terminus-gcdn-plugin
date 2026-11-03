<?php

namespace Pantheon\TerminusGCDN\Tests\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusGCDN\Commands\UpdatePluginCommand;
use Pantheon\TerminusGCDN\Tests\Support\ArrayLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * gcdn:update. GitHub, the `terminus self:plugin:*` processes, the installed
 * version and Terminus's plugin composer.json are replaced through the
 * command's seams, so nothing leaves the machine.
 */
final class UpdatePluginCommandTest extends TestCase
{
    private const RELEASES = 'https://api.github.com/repos/pantheon-systems/terminus-gcdn-plugin/releases/latest';
    private const TAGS = 'https://api.github.com/repos/pantheon-systems/terminus-gcdn-plugin/tags?per_page=1';

    private BufferedOutput $out;
    private ArrayLogger $logger;

    private const PKG = 'pantheon-systems/terminus-gcdn-plugin';
    private const UPDATE = 'terminus self:plugin:update ' . self::PKG;
    private const UNINSTALL = 'terminus self:plugin:uninstall ' . self::PKG;
    private const INSTALL = 'terminus self:plugin:install ' . self::PKG;

    /**
     * @param array<string, string|false> $responses URL => body (false = failure)
     * @param array<string, int> $exits command => exit code (default 0)
     * @param array<int, string|null> $installed the version `self:plugin:list` reports,
     *   one per read, in order (null = not listed); the last one repeats
     * @param array|null $plugins Terminus's plugin composer.json
     */
    private function command(array $responses, array $exits = [], array $installed = [], ?array $plugins = null): object
    {
        $cmd = new class ($responses, $exits, $installed, $plugins) extends UpdatePluginCommand {
            public array $fetched = [];
            public array $ran = [];

            public function __construct(private array $responses, private array $exits, private array $installed, private ?array $plugins)
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
                return $this->exits[$command] ?? 0;
            }

            protected function captureProcess(string $command): ?string
            {
                $version = count($this->installed) > 1 ? array_shift($this->installed) : ($this->installed[0] ?? null);
                return json_encode($version === null ? [] : [['name' => 'terminus-gcdn-plugin', 'installed_version' => $version]]);
            }

            protected function pluginsComposerJson(): ?array
            {
                return $this->plugins;
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
        $this->assertSame(0, $cmd->update());
        $this->assertSame([], $cmd->ran);
        $this->assertStringContainsString('is up to date (v' . UpdatePluginCommand::VERSION . ')', $this->plain());
    }

    /** 0.5.1 isn't offered an older or equal release, and is offered a newer one. */
    public function testOnlyANewerReleaseIsOffered(): void
    {
        $this->assertSame('0.5.1', UpdatePluginCommand::VERSION);

        foreach (['0.4.0', '0.5.0', '0.5.1', 'v0.5.1', '0.5.1-beta.1'] as $tag) {
            $cmd = $this->command([self::RELEASES => self::release($tag)]);
            $cmd->update();
            $this->assertSame([], $cmd->ran, $tag);
        }

        $cmd = $this->command([self::RELEASES => self::release('0.5.2')], [], ['0.5.2']);
        $cmd->update();
        $this->assertSame([self::UPDATE], $cmd->ran);
    }

    /** Within the recorded constraint (0.5.1 to 0.5.2), self:plugin:update is enough. */
    public function testNewerReleaseRunsSelfPluginUpdate(): void
    {
        $cmd = $this->command([self::RELEASES => self::release('99.0.0')], [], ['99.0.0']);
        $this->assertSame(0, $cmd->update());
        $this->assertSame([self::UPDATE], $cmd->ran);
        $this->assertStringContainsString('Updated to v99.0.0.', $this->plain());
    }

    /**
     * G2 (0.4.0 to 0.5.0, 2026-11-03): self:plugin:update exited 0 and changed
     * nothing, and gcdn:update said "Updated". Now it confirms the version,
     * reinstalls, and reports the version that's actually installed.
     */
    public function testG2AnUpdateThatDoesntTakeReinstalls(): void
    {
        $cmd = $this->command([self::RELEASES => self::release('0.6.0')], [], [UpdatePluginCommand::VERSION, '0.6.0']);
        $this->assertSame(0, $cmd->update());
        $this->assertSame([self::UPDATE, self::UNINSTALL, self::INSTALL], $cmd->ran);
        $this->assertStringContainsString('Updated to v0.6.0.', $this->plain());
        $this->assertStringContainsString('notice: Reinstalling terminus-gcdn-plugin to reach v0.6.0...', $this->logger->text());
    }

    /** A failed self:plugin:update (exit 1, as when the constraint changed) also goes on to reinstall. */
    public function testAFailedSelfPluginUpdateStillReinstalls(): void
    {
        $cmd = $this->command([self::RELEASES => self::release('0.6.0')], [self::UPDATE => 1], [UpdatePluginCommand::VERSION, '0.6.0']);
        $this->assertSame(0, $cmd->update());
        $this->assertSame([self::UPDATE, self::UNINSTALL, self::INSTALL], $cmd->ran);
    }

    /** The install fails after the uninstall: this version goes back, and the command says so. */
    public function testAFailedReinstallPutsThisVersionBack(): void
    {
        $cmd = $this->command(
            [self::RELEASES => self::release('0.6.0')],
            [self::INSTALL => 1],
            [UpdatePluginCommand::VERSION, null, UpdatePluginCommand::VERSION]
        );
        $this->assertSame(1, $cmd->update());
        $this->assertSame([self::UPDATE, self::UNINSTALL, self::INSTALL, self::INSTALL . ':^' . UpdatePluginCommand::VERSION], $cmd->ran);
        $out = $this->plain();
        $this->assertStringContainsString('Automatic update failed: v' . UpdatePluginCommand::VERSION . ' is still installed.', $out);
        $this->assertStringContainsString('  terminus self:plugin:uninstall ' . self::PKG, $out);
        $this->assertStringNotContainsString('Updated to', $out);
    }

    /** A failed uninstall leaves the plugin alone: no install, no restore. */
    public function testAFailedUninstallInstallsNothing(): void
    {
        $cmd = $this->command([self::RELEASES => self::release('0.6.0')], [self::UNINSTALL => 1], [UpdatePluginCommand::VERSION]);
        $this->assertSame(1, $cmd->update());
        $this->assertSame([self::UPDATE, self::UNINSTALL], $cmd->ran);
    }

    /** A local checkout (path repository or dev branch) is never uninstalled. */
    public function testALocalCheckoutIsLeftAlone(): void
    {
        $path = ['require' => [self::PKG => 'dev-main'], 'repositories' => [['type' => 'path', 'url' => '/src/terminus-gcdn-plugin']]];
        $dev = ['require' => [self::PKG => 'dev-some-branch']];
        foreach ([$path, $dev] as $plugins) {
            $cmd = $this->command([self::RELEASES => self::release('0.6.0')], [], [], $plugins);
            $this->assertSame(0, $cmd->update());
            $this->assertSame([], $cmd->ran);
            $this->assertStringContainsString('installed from a local checkout, so gcdn:update leaves it alone', $this->plain());
        }

        // A released install's composer.json doesn't count.
        $cmd = $this->command([self::RELEASES => self::release('0.6.0')], [], ['0.6.0'], ['require' => [self::PKG => '^0.5.1']]);
        $cmd->update();
        $this->assertSame([self::UPDATE], $cmd->ran);
    }

    public function testLeadingVIsIgnored(): void
    {
        $cmd = $this->command([self::RELEASES => self::release('v99.0.0')], [], ['99.0.0']);
        $cmd->update();
        $this->assertStringContainsString('→ v99.0.0', $this->plain());
    }

    public function testFallsBackToTagsWhenReleasesFail(): void
    {
        $cmd = $this->command([self::TAGS => json_encode([['name' => '99.0.0']])], [], ['99.0.0']);
        $cmd->update();
        $this->assertSame([self::RELEASES, self::TAGS], $cmd->fetched);
        $this->assertSame([self::UPDATE], $cmd->ran);
    }

    public function testBothSourcesFailingThrows(): void
    {
        $cmd = $this->command([]);
        $this->expectException(TerminusException::class);
        $this->expectExceptionMessage('Could not check for updates.');
        $cmd->update();
    }

    /** G2: when nothing works, it prints the manual steps and exits 1 (it used to exit 0). */
    public function testG2FailedUpdateExitsOne(): void
    {
        $cmd = $this->command(
            [self::RELEASES => self::release('99.0.0')],
            [self::UPDATE => 1, self::UNINSTALL => 1],
            [UpdatePluginCommand::VERSION]
        );
        $this->assertSame(1, $cmd->update());
        $this->assertStringContainsString('Automatic update failed: v' . UpdatePluginCommand::VERSION . ' is still installed.', $this->plain());
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

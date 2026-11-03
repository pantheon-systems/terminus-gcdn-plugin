<?php

namespace Pantheon\TerminusGCDN\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Helpers\LocalMachineHelper;

/**
 * Class UpdatePluginCommand.
 *
 * Checks for new releases of the terminus-gcdn-plugin on GitHub and
 * updates the installed plugin.
 *
 * `terminus self:plugin:install` records a caret constraint (`^0.4.0`), and
 * for 0.x versions that stops below the next minor (0.5.0), so
 * `self:plugin:update` alone can't reach it: Terminus's partial update keeps
 * the locked version. When the installed version doesn't change, this
 * reinstalls the plugin, and it only reports an update it has confirmed.
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class UpdatePluginCommand extends TerminusCommand
{
    use DomainOutputTrait;

    const VERSION = '0.5.1';
    const GITHUB_REPO = 'pantheon-systems/terminus-gcdn-plugin';
    const COMPOSER_PACKAGE = 'pantheon-systems/terminus-gcdn-plugin';


    /**
     * Checks for a newer version of the GCDN plugin and updates it.
     *
     * @command gcdn:update
     *
     * @usage Checks for updates and installs the latest version if available.
     *
     * @return int 0 when up to date or updated, 1 when the update failed.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function update()
    {
        $this->log()->notice('Checking for updates...');

        $latestTag = $this->getLatestReleaseTag();
        if ($latestTag === null) {
            throw new TerminusException(
                'Could not check for updates. Verify your network connection and try again.'
            );
        }

        $latestVersion = ltrim($latestTag, 'v');
        $currentVersion = self::VERSION;

        if (version_compare($latestVersion, $currentVersion, '<=')) {
            $this->output()->writeln(
                $this->paint('terminus-gcdn-plugin is up to date (v' . $currentVersion . ').', 'green')
            );
            return 0;
        }

        $this->output()->writeln(
            $this->paint('Update available: ', 'cyan')
            . $this->paint('v' . $currentVersion, 'bold')
            . $this->paint(' → ', 'cyan')
            . $this->paint('v' . $latestVersion, 'bold')
        );
        $this->output()->writeln('');

        if ($this->installedFromLocalPath()) {
            $this->output()->writeln($this->paint(
                'This plugin is installed from a local checkout, so gcdn:update leaves it alone.',
                'yellow'
            ));
            $this->output()->writeln('Update the checkout (git pull), or switch to the released plugin:');
            $this->writeReinstallSteps();
            return 0;
        }

        $this->log()->notice('Updating terminus-gcdn-plugin...');
        $this->runProcess(sprintf('terminus self:plugin:update %s', self::COMPOSER_PACKAGE));
        $installed = $this->installedVersion();

        if (!$this->isAtLeast($installed, $latestVersion)) {
            // The constraint recorded at install time holds it back: reinstall.
            $this->log()->notice('Reinstalling terminus-gcdn-plugin to reach v{version}...', ['version' => $latestVersion]);
            $uninstalled = $this->runProcess(sprintf('terminus self:plugin:uninstall %s', self::COMPOSER_PACKAGE)) === 0;
            if ($uninstalled) {
                $this->runProcess(sprintf('terminus self:plugin:install %s', self::COMPOSER_PACKAGE));
            }
            $installed = $this->installedVersion();
            if ($uninstalled && $installed === null) {
                // The install failed after the uninstall: put this version back.
                $this->runProcess(sprintf('terminus self:plugin:install %s:^%s', self::COMPOSER_PACKAGE, $currentVersion));
                $installed = $this->installedVersion();
            }
        }

        $this->output()->writeln('');
        if ($this->isAtLeast($installed, $latestVersion)) {
            $this->output()->writeln($this->paint('Updated to v' . $installed . '.', 'green'));
            return 0;
        }

        $this->output()->writeln($this->paint(
            'Automatic update failed: ' . ($installed !== null ? 'v' . $installed . ' is still installed.' : 'the plugin isn\'t installed.'),
            'red'
        ));
        $this->output()->writeln('To install v' . $latestVersion . ', reinstall the plugin:');
        $this->writeReinstallSteps();
        return 1;
    }

    private function writeReinstallSteps(): void
    {
        $this->output()->writeln('  terminus self:plugin:uninstall ' . self::COMPOSER_PACKAGE);
        $this->output()->writeln('  terminus self:plugin:install ' . self::COMPOSER_PACKAGE);
    }

    private function isAtLeast(?string $installed, string $version): bool
    {
        return $installed !== null && version_compare($installed, $version, '>=');
    }

    /**
     * The installed version, from `terminus self:plugin:list`, or null when
     * the plugin isn't listed or the list can't be read.
     */
    private function installedVersion(): ?string
    {
        $plugins = json_decode((string) $this->captureProcess('terminus self:plugin:list --format=json'), true);
        foreach (is_array($plugins) ? $plugins : [] as $plugin) {
            if (($plugin['name'] ?? '') === 'terminus-gcdn-plugin') {
                return ltrim((string) ($plugin['installed_version'] ?? ''), 'v') ?: null;
            }
        }
        return null;
    }

    /**
     * Installed from a path repository or a dev branch (a local checkout):
     * reinstalling would replace it with the released plugin.
     */
    private function installedFromLocalPath(): bool
    {
        $composer = $this->pluginsComposerJson();
        if ($composer === null) {
            return false;
        }
        if (str_starts_with((string) ($composer['require'][self::COMPOSER_PACKAGE] ?? ''), 'dev-')) {
            return true;
        }
        foreach ($composer['repositories'] ?? [] as $repository) {
            if (is_array($repository) && ($repository['type'] ?? '') === 'path') {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetches the latest release tag from GitHub.
     */
    private function getLatestReleaseTag(): ?string
    {
        $url = sprintf(
            'https://api.github.com/repos/%s/releases/latest',
            self::GITHUB_REPO
        );

        $json = $this->httpGet($url);
        if ($json === false) {
            return $this->getLatestTagFallback();
        }

        $data = json_decode($json, true);
        return $data['tag_name'] ?? null;
    }

    /**
     * Fallback: fetches the latest tag via the tags API (works even
     * without formal GitHub Releases).
     */
    private function getLatestTagFallback(): ?string
    {
        $url = sprintf(
            'https://api.github.com/repos/%s/tags?per_page=1',
            self::GITHUB_REPO
        );

        $json = $this->httpGet($url);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data)) {
            return null;
        }

        return $data[0]['name'] ?? null;
    }

    /**
     * Fetches a GitHub API URL. Returns the body, or false on any failure.
     * A seam for tests.
     *
     * @return string|false
     */
    protected function httpGet(string $url)
    {
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: terminus-gcdn-plugin\r\nAccept: application/vnd.github.v3+json\r\n",
                'timeout' => 10,
            ],
        ]);

        return @file_get_contents($url, false, $context);
    }

    /**
     * Terminus's plugin composer.json (where `self:plugin:install` records
     * the plugin), or null when it can't be read. A seam for tests.
     */
    protected function pluginsComposerJson(): ?array
    {
        try {
            $file = $this->getConfig()->get('plugins_dir') . '/composer.json';
        } catch (\Throwable $e) {
            return null;
        }
        $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data) ? $data : null;
    }

    /**
     * Runs a shell command and returns its standard output, or null when it
     * fails. A seam for tests.
     */
    protected function captureProcess(string $command): ?string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process) === 0 ? (string) $output : null;
    }

    /**
     * Runs a shell command, streaming its output, and returns its exit code.
     * A seam for tests.
     */
    protected function runProcess(string $command): int
    {
        $exitCode = 0;
        passthru($command, $exitCode);
        return $exitCode;
    }
}

<?php

namespace Pantheon\TerminusGCDN\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Helpers\LocalMachineHelper;

/**
 * Class UpdatePluginCommand.
 *
 * Checks for new releases of the terminus-gcdn-plugin on GitHub and
 * updates the installed plugin via `terminus self:plugin:update`.
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class UpdatePluginCommand extends TerminusCommand
{
    const VERSION = '0.3.0';
    const GITHUB_REPO = 'pantheon-systems/terminus-gcdn-plugin';
    const COMPOSER_PACKAGE = 'pantheon-systems/terminus-gcdn-plugin';

    const YELLOW = "\033[33m";
    const GREEN = "\033[32m";
    const RED = "\033[31m";
    const CYAN = "\033[36m";
    const RESET = "\033[0m";
    const BOLD = "\033[1m";

    /**
     * Checks for a newer version of the GCDN plugin and updates it.
     *
     * @command gcdn:update
     *
     * @usage Checks for updates and installs the latest version if available.
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
                self::GREEN . 'terminus-gcdn-plugin is up to date (v' . $currentVersion . ').' . self::RESET
            );
            return;
        }

        $this->output()->writeln(
            self::CYAN . 'Update available: '
            . self::BOLD . 'v' . $currentVersion . self::RESET
            . self::CYAN . ' → '
            . self::BOLD . 'v' . $latestVersion . self::RESET
        );
        $this->output()->writeln('');

        $this->log()->notice('Updating terminus-gcdn-plugin...');

        $command = sprintf('terminus self:plugin:update %s', self::COMPOSER_PACKAGE);
        $exitCode = 0;
        passthru($command, $exitCode);

        if ($exitCode !== 0) {
            $this->output()->writeln('');
            $this->output()->writeln(
                self::RED . 'Automatic update failed.' . self::RESET
            );
            $this->output()->writeln('Try manually:');
            $this->output()->writeln("  terminus self:plugin:update " . self::COMPOSER_PACKAGE);
            $this->output()->writeln('Or reinstall:');
            $this->output()->writeln("  terminus self:plugin:uninstall " . self::COMPOSER_PACKAGE);
            $this->output()->writeln("  terminus self:plugin:install " . self::COMPOSER_PACKAGE);
            return;
        }

        $this->output()->writeln('');
        $this->output()->writeln(
            self::GREEN . 'Updated to v' . $latestVersion . '.' . self::RESET
        );
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

        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: terminus-gcdn-plugin\r\nAccept: application/vnd.github.v3+json\r\n",
                'timeout' => 10,
            ],
        ]);

        $json = @file_get_contents($url, false, $context);
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

        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: terminus-gcdn-plugin\r\nAccept: application/vnd.github.v3+json\r\n",
                'timeout' => 10,
            ],
        ]);

        $json = @file_get_contents($url, false, $context);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data)) {
            return null;
        }

        return $data[0]['name'] ?? null;
    }
}

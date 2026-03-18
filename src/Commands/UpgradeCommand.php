<?php

namespace Pantheon\TerminusGCDN\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Request\RequestAwareTrait;

/**
 * Class UpgradeCommand.
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class UpgradeCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
    use SiteAwareTrait;
    use RequestAwareTrait;

    /**
     * Upgrades a site to GCDN with bot protection.
     *
     * @authorize
     *
     * @command gcdn:upgrade
     *
     * @param string $site_id Site name
     *
     * @usage <site> Enables GCDN upgrade for <site>.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function upgrade($site_id)
    {
        $site = $this->getSiteById($site_id);
        $url = sprintf('sites/%s/hostnames/cdn-migration', $site->id);

        $response = $this->request()->request($url, [
            'method' => 'POST',
            'json' => ['environments' => ['dev', 'test', 'live']],
        ]);

        if ($response->isError()) {
            throw new TerminusException(
                'Failed to enable GCDN upgrade for {site}.',
                ['site' => $site_id]
            );
        }

        $this->log()->notice(
            'GCDN upgrade has been enabled for {site}.',
            ['site' => $site_id]
        );
    }
}

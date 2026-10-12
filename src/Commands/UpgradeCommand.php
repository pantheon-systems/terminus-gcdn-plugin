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
 * Migrates a site from Fastly to new-gcdn. Calls the
 * migration endpoint per environment, then triggers
 * converge_site to update DNS records (Route53) for platform hostnames.
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class UpgradeCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
    use SiteAwareTrait;
    use RequestAwareTrait;

    /**
     * Upgrades a site from Fastly to new-gcdn.
     *
     * Migrates all environments (dev, test, live) to new-gcdn and
     * triggers a site converge to update platform hostname DNS.
     *
     * @authorize
     *
     * @command gcdn:upgrade
     *
     * @param string $site_id Site name or UUID
     *
     * @usage <site> Migrates <site> from Fastly to new-gcdn.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function upgrade($site_id)
    {
        $site = $this->getSiteById($site_id);
        $environments = ['dev', 'test', 'live'];

        // Step 1: Trigger cdn_migration workflow via site-level workflows endpoint
        $this->log()->notice('Migrating {site} to new-gcdn...', ['site' => $site->getName()]);

        $migrateUrl = sprintf('sites/%s/workflows', $site->id);

        $migrateResponse = $this->request()->request($migrateUrl, [
            'method' => 'POST',
            'form_params' => [
                'type' => 'cdn_migration',
                'params' => (object)['environments' => $environments],
            ],
        ]);

        // Re-running on a migrated site succeeds (the migration is
        // idempotent), so any error is a real failure (U1). Titan's error
        // bodies are usually bare JSON strings, e.g. "Bad params" (U2).
        if ($migrateResponse->isError()) {
            $status = 'HTTP ' . $migrateResponse->getStatusCode();
            $detail = $this->errorDetail($migrateResponse->getData());
            throw new TerminusException(
                'Failed to migrate {site} to new-gcdn: {msg}',
                ['site' => $site_id, 'msg' => $detail !== '' ? "{$status}: {$detail}" : $status]
            );
        }
        $this->log()->notice('Migration triggered for all environments.');

        // Step 2: Trigger converge_site to update Route53 for platform hostnames
        $this->log()->notice('Converging site to update DNS...');

        $convergeUrl = sprintf('sites/%s/workflows', $site->id);

        $convergeResponse = $this->request()->request($convergeUrl, [
            'method' => 'POST',
            'form_params' => ['type' => 'converge_site', 'params' => (object)[]],
        ]);

        if ($convergeResponse->isError()) {
            $this->log()->warning(
                'Failed to trigger site converge. Platform hostname DNS may not update automatically.'
            );
        } else {
            $this->log()->notice('Site converge triggered.');
        }

        $this->log()->notice(
            'GCDN upgrade complete for {site}. Run "terminus domain:dns {site}.live" to see updated DNS records.',
            ['site' => $site->getName()]
        );
    }

    /**
     * The message in an error body: a string, or an object's `message`.
     * HTML pages (a proxy error) are dropped, and long text is cut.
     */
    private function errorDetail($data): string
    {
        if (is_object($data) && isset($data->message) && is_scalar($data->message)) {
            $detail = trim((string) $data->message);
        } elseif (is_string($data)) {
            $detail = trim($data);
        } else {
            return '';
        }
        if (strpos($detail, '<') === 0) {
            return '';
        }
        return strlen($detail) > 200 ? substr($detail, 0, 200) . '...' : $detail;
    }
}

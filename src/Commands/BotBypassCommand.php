<?php

namespace Pantheon\TerminusGCDN\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Request\RequestAwareTrait;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusGCDN\BotBypassApi;
use Pantheon\TerminusGCDN\BotBypassResponse;

/**
 * Class BotBypassCommand.
 *
 * Retrieves a site's bot-bypass tokens so a customer can exempt their own
 * monitors and automation from GCDN bot protection.
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class BotBypassCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
    use SiteAwareTrait;
    use RequestAwareTrait;

    /**
     * Displays the bot-bypass tokens for a site.
     *
     * Returns the current token and its forward-dated successor, with the
     * window each is accepted in and the request header to send it in.
     * One token covers every environment on the site (dev, test, live and
     * all multidevs): tokens derive from the site, not the environment.
     *
     * @authorize
     *
     * @command gcdn:bot-bypass
     *
     * @param string $site Site name or UUID (an environment suffix is ignored)
     *
     * @field-labels
     *     role: Token
     *     token: Value
     *     valid_from: Valid From
     *     expires_at: Expires
     *     header_name: Header
     * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
     *
     * @usage <site> Shows the current and next bot-bypass tokens for <site>.
     * @usage <site> --format=json Machine-readable output for CI or monitoring configuration.
     * @usage <site> --format=json --fields=role,token,valid_from Minimal JSON for scripts.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function botBypass($site)
    {
        $siteModel = $this->getSiteById($site);

        $api = new BotBypassApi($this->request());
        $result = $api->fetchTokens($siteModel->id);

        $error = BotBypassResponse::errorMessage($result->getStatusCode(), $siteModel->getName());
        if ($error !== null) {
            throw new TerminusException($error);
        }

        $rows = BotBypassResponse::rows($result->getData());

        foreach (BotBypassResponse::guidance($rows) as $line) {
            $this->log()->notice($line);
        }

        return new RowsOfFields($rows);
    }
}

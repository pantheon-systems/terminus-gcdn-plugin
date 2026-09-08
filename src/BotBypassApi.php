<?php

namespace Pantheon\TerminusGCDN;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Request\Request;
use Pantheon\Terminus\Request\RequestOperationResult;

/**
 * Class BotBypassApi.
 *
 * Client for pantheonapi's native bot-bypass token endpoint
 * (`/bot-bypass/v1/sites/{site}/token`).
 *
 * Core Terminus prepends `/api/` to every relative request path and proxies
 * that family to Yggdrasil, so a pantheonapi-native route must be called with
 * an absolute URL. That branch of Request::request() does not add the
 * Authorization header, so it is set here. This mirrors core's SecretsApi.
 *
 * The response carries live bearer credentials. Nothing in this class logs
 * the response or its data at any verbosity.
 *
 * @package Pantheon\TerminusGCDN
 */
class BotBypassApi
{
    /**
     * Substring of the exception core Terminus throws after its retry
     * middleware gives up on a 5xx. The command never sees the status code
     * in that case, so this is the only signal that the upstream is down.
     */
    public const RETRY_EXHAUSTED_MARKER = 'Maximum retry attempts reached';

    public const UNAVAILABLE_MESSAGE =
        'The bot-bypass token service did not respond successfully after several attempts '
        . '(unavailable or rate-limited). See the error above and try again shortly.';

    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Fetches the current and next bot-bypass tokens for a site.
     *
     * @param string $siteId Site UUID.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function fetchTokens(string $siteId): RequestOperationResult
    {
        $url = sprintf('%s/sites/%s/token', $this->getBaseURI(), rawurlencode($siteId));
        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => $this->request->session()->get('session'),
            ],
        ];

        try {
            return $this->request->request($url, $options);
        } catch (TerminusException $e) {
            if (strpos($e->getMessage(), self::RETRY_EXHAUSTED_MARKER) !== false) {
                throw new TerminusException(self::UNAVAILABLE_MESSAGE);
            }
            throw $e;
        }
    }

    /**
     * Builds the absolute base URI for the bot-bypass service.
     *
     * Same resolution as core's SecretsApi so the command works against a
     * sandbox: honours papi_* overrides, rewrites a hermes sandbox host to
     * its pantheonapi counterpart, and falls back to the production host.
     */
    private function getBaseURI(): string
    {
        $config = $this->request->getConfig();

        $protocol = $config->get('papi_protocol') ?? $config->get('protocol');
        $port = $config->get('papi_port') ?? $config->get('port');
        $host = $config->get('papi_host');
        if (!$host && strpos((string) $config->get('host'), 'hermes.sandbox-') !== false) {
            $host = str_replace('hermes', 'pantheonapi', $config->get('host'));
        }
        if (!$host && strpos((string) $config->get('host'), 'sandbox-') !== false) {
            $host = $config->get('host');
        }
        if (!$host) {
            $host = 'terminus.pantheon.io';
        }

        return sprintf('%s://%s:%s/bot-bypass/v1', $protocol, $host, $port);
    }
}

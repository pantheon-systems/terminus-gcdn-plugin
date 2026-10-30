<?php

namespace Pantheon\TerminusGCDN;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Request\Request;
use Pantheon\Terminus\Request\RequestOperationResult;

/**
 * Class UABypassApi.
 *
 * Client for pantheonapi's User-Agent bypass endpoint
 * (`/ua-bypass/v1/sites/{site}/environments/{env}/hostnames/{hostname}`).
 * GET lists a hostname's entries; POST adds or removes one.
 *
 * The entry travels in the JSON body, never the path or query, so it stays
 * out of ingress and access logs. Add and remove are idempotent, so core
 * Terminus retrying a POST on a 5xx cannot apply a change twice.
 *
 * @package Pantheon\TerminusGCDN
 */
class UABypassApi
{
    public const UNAVAILABLE_MESSAGE =
        'The User-Agent bypass service did not respond successfully after several attempts '
        . '(unavailable or rate-limited). If you were adding or removing an entry, run '
        . '`terminus gcdn:ua-bypass:list` to check whether it was applied, then try again.';

    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Lists the entries stored for one hostname of a site environment.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function list(string $siteId, string $env, string $hostname): RequestOperationResult
    {
        return $this->send($this->url($siteId, $env, $hostname), ['method' => 'get']);
    }

    /**
     * Adds or removes one entry. acknowledge_browser_ua is sent only on add,
     * and only when the caller explicitly opted in.
     *
     * @param string $action `add` or `remove`.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function change(
        string $siteId,
        string $env,
        string $hostname,
        string $action,
        string $entry,
        bool $acknowledgeBrowserUA = false
    ): RequestOperationResult {
        $body = ['action' => $action, 'entry' => $entry];
        if ($action === 'add' && $acknowledgeBrowserUA) {
            $body['acknowledge_browser_ua'] = true;
        }

        return $this->send(
            $this->url($siteId, $env, $hostname),
            ['method' => 'post', 'form_params' => $body]
        );
    }

    private function url(string $siteId, string $env, string $hostname): string
    {
        return sprintf(
            '%s/sites/%s/environments/%s/hostnames/%s',
            PantheonApiUri::base($this->request->getConfig(), 'ua-bypass/v1'),
            rawurlencode($siteId),
            rawurlencode($env),
            rawurlencode($hostname)
        );
    }

    /**
     * Sends the request with the session in the Authorization header, which
     * core's absolute-URL branch does not add.
     */
    private function send(string $url, array $options): RequestOperationResult
    {
        $options['headers'] = [
            'Accept' => 'application/json',
            'Authorization' => $this->request->session()->get('session'),
        ];

        try {
            return $this->request->request($url, $options);
        } catch (TerminusException $e) {
            if (strpos($e->getMessage(), BotBypassApi::RETRY_EXHAUSTED_MARKER) !== false) {
                throw new TerminusException(self::UNAVAILABLE_MESSAGE);
            }
            throw $e;
        }
    }
}

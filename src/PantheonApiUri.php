<?php

namespace Pantheon\TerminusGCDN;

use Consolidation\Config\ConfigInterface;

/**
 * Class PantheonApiUri.
 *
 * Builds the absolute base URI of a pantheonapi-native service.
 *
 * Core Terminus prepends `/api/` to every relative request path and proxies
 * that family to Yggdrasil, so a pantheonapi-native route must be called with
 * an absolute URL. The host resolution is the same as core's SecretsApi so the
 * commands work against a sandbox: it honours papi_* overrides, rewrites a
 * hermes sandbox host to its pantheonapi counterpart, and falls back to the
 * production host.
 *
 * @package Pantheon\TerminusGCDN
 */
final class PantheonApiUri
{
    /**
     * @param string $servicePath Service prefix without slashes, e.g. `bot-bypass/v1`.
     */
    public static function base(ConfigInterface $config, string $servicePath): string
    {
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

        return sprintf('%s://%s:%s/%s', $protocol, $host, $port, $servicePath);
    }
}

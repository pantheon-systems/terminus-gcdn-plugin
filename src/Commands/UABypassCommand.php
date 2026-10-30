<?php

namespace Pantheon\TerminusGCDN\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Request\RequestAwareTrait;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusGCDN\UABypassApi;
use Pantheon\TerminusGCDN\UABypassResponse;

/**
 * Class UABypassCommand.
 *
 * Manages the per-domain User-Agent bypass list: requests whose User-Agent
 * exactly equals an entry skip the GCDN bot challenge on that domain. For
 * customers' own monitors and tools that cannot send a bot-bypass token.
 *
 * @package Pantheon\TerminusGCDN\Commands
 */
class UABypassCommand extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
    use SiteAwareTrait;
    use RequestAwareTrait;

    /**
     * Upper bound on the domains looked up when list runs without
     * --hostname: one request each, so a large environment stays bounded.
     */
    public const LIST_HOSTNAME_LIMIT = 50;

    /**
     * Lists the User-Agent bypass entries of an environment's domains.
     *
     * Without --hostname, every custom domain of the environment is checked
     * (up to 50). Wildcard domains are skipped, and domains without entries
     * are not shown.
     *
     * @authorize
     *
     * @command gcdn:ua-bypass:list
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @option hostname Only list the entries of this domain
     *
     * @field-labels
     *     hostname: Domain
     *     entry: User-Agent
     * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
     *
     * @usage <site>.<env> Lists the entries of every custom domain of <site>.<env>.
     * @usage <site>.<env> --hostname=<domain> Lists the entries of <domain> only.
     * @usage <site>.<env> --format=json Machine-readable output.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function listEntries($site_env, $options = ['hostname' => null])
    {
        $env = $this->getEnv($site_env);
        $site = $this->getSiteById($site_env);
        $label = sprintf('%s.%s', $site->getName(), $env->id);

        $explicit = !empty($options['hostname']);
        $hostnames = $explicit
            ? [UABypassResponse::hostname((string) $options['hostname'])]
            : $this->customHostnames($env, $label);

        $api = new UABypassApi($this->request());
        $rows = [];
        foreach ($hostnames as $hostname) {
            $result = $api->list($site->id, $env->id, $hostname);
            $data = $result->getData();
            $error = UABypassResponse::errorMessage($result->getStatusCode(), $data, $label, $hostname, 'list');
            if ($error !== null) {
                // One domain's problem should not hide the others' entries;
                // anything else (session, access, service) applies to all.
                if ($explicit || !UABypassResponse::isHostnameError($data)) {
                    throw new TerminusException($error);
                }
                $this->log()->warning($error);
                continue;
            }
            $rows = array_merge($rows, UABypassResponse::rows($hostname, UABypassResponse::entries($data)));
        }

        if (empty($rows) && !empty($hostnames)) {
            $this->log()->notice('No User-Agent bypass entries found for {target}.', [
                'target' => $explicit ? $hostnames[0] : $label,
            ]);
        }

        return new RowsOfFields($rows);
    }

    /**
     * Adds a User-Agent bypass entry to a domain.
     *
     * Requests whose User-Agent header exactly equals the entry skip the GCDN
     * bot challenge on that domain. Matching is exact and case-sensitive: the
     * entry must be the client's full User-Agent string, including its version,
     * and must be updated whenever the client's version string changes. Quote
     * the value in your shell. Other bot protections still apply.
     *
     * An entry is 1 to 256 printable ASCII characters, with no double quote or
     * backslash and no leading or trailing space. A domain can have up to 8
     * entries.
     *
     * An entry that looks like a web browser's User-Agent is refused, because
     * any bot imitating that browser would also skip the challenge. Pass
     * --allow-browser-ua to add one anyway after confirming.
     *
     * Saved changes may take a few minutes to take effect.
     *
     * @authorize
     *
     * @command gcdn:ua-bypass:add
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $hostname Domain e.g. `www.example.com`
     * @param string $user_agent The exact, full User-Agent string to exempt
     * @option allow-browser-ua Add an entry that looks like a web browser User-Agent, after confirmation
     *
     * @field-labels
     *     hostname: Domain
     *     entry: User-Agent
     * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
     *
     * @usage <site>.<env> <domain> "ExampleMonitor/2.1 (+https://example.com/monitor)" Exempts that exact User-Agent on <domain>.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function add($site_env, $hostname, $user_agent, $options = ['allow-browser-ua' => false])
    {
        $hostname = UABypassResponse::hostname((string) $hostname);
        $this->assertValidEntry((string) $user_agent);

        $env = $this->getEnv($site_env);
        $site = $this->getSiteById($site_env);
        $label = sprintf('%s.%s', $site->getName(), $env->id);

        $allowBrowser = !empty($options['allow-browser-ua']);
        if ($allowBrowser && !$this->confirm(
            'If this User-Agent looks like a web browser, any client that sends exactly this string, '
            . 'including bots that imitate that browser, will skip the bot challenge on {hostname}. Add it anyway?',
            ['hostname' => $hostname]
        )) {
            $this->log()->notice('Nothing was added.');
            return new RowsOfFields([]);
        }

        $api = new UABypassApi($this->request());
        $result = $api->change($site->id, $env->id, $hostname, 'add', (string) $user_agent, $allowBrowser);
        $error = UABypassResponse::errorMessage(
            $result->getStatusCode(),
            $result->getData(),
            $label,
            $hostname,
            'add'
        );
        if ($error !== null) {
            throw new TerminusException($error);
        }

        $entries = UABypassResponse::entries($result->getData());
        if (UABypassResponse::browserLike($result->getData())) {
            $this->log()->warning(
                'This entry looks like a web browser User-Agent. Any client sending exactly this string '
                . 'skips the bot challenge on {hostname}.',
                ['hostname' => $hostname]
            );
        }
        $this->logSaved($hostname, $entries);

        return new RowsOfFields(UABypassResponse::rows($hostname, $entries));
    }

    /**
     * Removes a User-Agent bypass entry from a domain.
     *
     * The value must match the stored entry exactly. Run
     * `terminus gcdn:ua-bypass:list` to see the stored values. Removing an
     * entry that is not there changes nothing.
     *
     * @authorize
     *
     * @command gcdn:ua-bypass:remove
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $hostname Domain e.g. `www.example.com`
     * @param string $user_agent The stored User-Agent entry to remove
     *
     * @field-labels
     *     hostname: Domain
     *     entry: User-Agent
     * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
     *
     * @usage <site>.<env> <domain> "ExampleMonitor/2.1 (+https://example.com/monitor)" Removes that entry from <domain>.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function remove($site_env, $hostname, $user_agent)
    {
        $hostname = UABypassResponse::hostname((string) $hostname);
        $this->assertValidEntry((string) $user_agent);

        $env = $this->getEnv($site_env);
        $site = $this->getSiteById($site_env);
        $label = sprintf('%s.%s', $site->getName(), $env->id);

        $api = new UABypassApi($this->request());
        $result = $api->change($site->id, $env->id, $hostname, 'remove', (string) $user_agent);
        $error = UABypassResponse::errorMessage(
            $result->getStatusCode(),
            $result->getData(),
            $label,
            $hostname,
            'remove'
        );
        if ($error !== null) {
            throw new TerminusException($error);
        }

        $entries = UABypassResponse::entries($result->getData());
        $this->logSaved($hostname, $entries);

        return new RowsOfFields(UABypassResponse::rows($hostname, $entries));
    }

    /**
     * @param string[] $entries The hostname's entries after the change.
     */
    private function logSaved(string $hostname, array $entries): void
    {
        $this->log()->notice(
            'Saved. {hostname} now has {count} User-Agent bypass {noun}. The change may take a few minutes to take effect.',
            [
                'hostname' => $hostname,
                'count' => count($entries),
                'noun' => count($entries) === 1 ? 'entry' : 'entries',
            ]
        );
    }

    /**
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function assertValidEntry(string $entry): void
    {
        $problem = UABypassResponse::entryProblem($entry);
        if ($problem !== null) {
            throw new TerminusException($problem);
        }
    }

    /**
     * The environment's custom domains that can carry entries, capped at
     * LIST_HOSTNAME_LIMIT. Wildcard domains are skipped: entries are per
     * exact hostname.
     *
     * @return string[]
     */
    private function customHostnames($env, string $label): array
    {
        $hostnames = [];
        $domains = $env->getDomains()->filter(
            function ($domain) {
                return $domain->get('type') === 'custom';
            }
        )->all();
        foreach ($domains as $domain) {
            try {
                $hostnames[] = UABypassResponse::hostname((string) $domain->id);
            } catch (TerminusException $e) {
                continue;
            }
        }

        if (empty($hostnames)) {
            $this->log()->notice('{site_env} has no custom domains.', ['site_env' => $label]);
            return [];
        }
        if (count($hostnames) > self::LIST_HOSTNAME_LIMIT) {
            $this->log()->warning(
                'Showing the first {limit} of {total} domains. Use --hostname to list another domain.',
                ['limit' => self::LIST_HOSTNAME_LIMIT, 'total' => count($hostnames)]
            );
            $hostnames = array_slice($hostnames, 0, self::LIST_HOSTNAME_LIMIT);
        }
        return $hostnames;
    }
}

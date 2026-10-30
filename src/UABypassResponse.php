<?php

namespace Pantheon\TerminusGCDN;

use Pantheon\Terminus\Exceptions\TerminusException;

/**
 * Class UABypassResponse.
 *
 * Pure validation and response mapping for the User-Agent bypass commands.
 * No I/O, so it is unit-testable without a network.
 *
 * The entry and hostname rules mirror the ua-bypass function's validate.ts
 * (EDRT-9779) so a typo fails before a round trip; the server still enforces
 * them. Messages name the rule broken, never the value.
 *
 * @package Pantheon\TerminusGCDN
 */
final class UABypassResponse
{
    public const ENTRY_MAX_LENGTH = 256;

    public const MALFORMED_MESSAGE = 'Unexpected response from the User-Agent bypass service.';

    private const HOSTNAME_MAX_LENGTH = 253;
    private const DNS_LABEL_MAX_LENGTH = 63;
    private const SERVER_MESSAGE_MAX_LENGTH = 512;

    /**
     * Returns what is wrong with an entry, or null when it is valid. The
     * entry is used exactly as given: nothing is trimmed.
     */
    public static function entryProblem(string $entry): ?string
    {
        if ($entry === '') {
            return 'The User-Agent must not be empty.';
        }
        if (strlen($entry) > self::ENTRY_MAX_LENGTH) {
            return sprintf('The User-Agent must be at most %d characters.', self::ENTRY_MAX_LENGTH);
        }
        if (!preg_match('/^[\x20-\x7e]+\z/', $entry)) {
            return 'The User-Agent must be printable ASCII.';
        }
        if (strpbrk($entry, '"\\') !== false) {
            return 'The User-Agent must not contain a double quote or a backslash.';
        }
        if ($entry[0] === ' ' || substr($entry, -1) === ' ') {
            return 'The User-Agent must not start or end with a space.';
        }
        return null;
    }

    /**
     * Lowercases and structurally checks a hostname. Whether it belongs to
     * the site environment is the server's call (hostname_not_found).
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public static function hostname(string $value): string
    {
        $hostname = strtolower($value);
        if ($hostname === '' || strlen($hostname) > self::HOSTNAME_MAX_LENGTH) {
            throw new TerminusException(
                sprintf('The domain must be 1 to %d characters.', self::HOSTNAME_MAX_LENGTH)
            );
        }
        foreach (explode('.', $hostname) as $label) {
            $tooLong = strlen($label) > self::DNS_LABEL_MAX_LENGTH;
            if ($tooLong || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?\z/', $label)) {
                throw new TerminusException('The domain must be a valid DNS name, without a wildcard.');
            }
        }
        return $hostname;
    }

    /**
     * Maps a non-200 response to a message the customer can act on, or null
     * for success. Uses the stable error code from the
     * `{"error": {"code", "message"}}` envelope; relays the server's message
     * text only for invalid_request, whose message names the rule broken.
     *
     * @param mixed $data Decoded JSON body.
     * @param string $action `list`, `add` or `remove`.
     */
    public static function errorMessage(int $status, $data, string $siteEnv, string $hostname, string $action): ?string
    {
        if ($status === 200) {
            return null;
        }

        $code = self::errorField($data, 'code');
        switch ($code) {
            case 'unauthenticated':
                return 'Your session has expired. Run `terminus auth:login` and try again.';
            case 'permission_denied':
                return sprintf('You do not have access to %s.', $siteEnv);
            case 'invalid_request':
                $detail = self::errorField($data, 'message');
                return $detail === ''
                    ? 'The request was rejected as invalid.'
                    : sprintf('The request was rejected: %s.', rtrim($detail, '.'));
            case 'browser_ua_rejected':
                return sprintf(
                    'This User-Agent has the shape of a web browser. Adding it would let any client that sends '
                    . 'exactly this string, including bots that imitate that browser, skip the bot challenge on %s. '
                    . 'If you are sure, run the command again with --allow-browser-ua.',
                    $hostname
                );
            case 'hostname_not_found':
                return sprintf(
                    '%s is not a domain of %s. Run `terminus domain:list %s` to see its domains.',
                    $hostname,
                    $siteEnv,
                    $siteEnv
                );
            case 'hostname_not_verified':
                return sprintf(
                    '%s has not been verified yet. Verify it with `terminus gcdn:verify %s %s`, then try again.',
                    $hostname,
                    $siteEnv,
                    $hostname
                );
            case 'hostname_not_provisioned':
                return sprintf(
                    '%s is not provisioned on GCDN yet. Try again once its setup has finished.',
                    $hostname
                );
            case 'hostname_not_cloudflare':
                return sprintf(
                    '%s is not served by GCDN, so User-Agent bypass entries do not apply to it. '
                    . 'See `terminus help gcdn:upgrade`.',
                    $hostname
                );
            case 'entry_limit_reached':
                return sprintf(
                    '%s already has the maximum number of User-Agent bypass entries. '
                    . 'Remove one with `terminus gcdn:ua-bypass:remove` first.',
                    $hostname
                );
            case 'rate_limited':
                return 'Too many User-Agent bypass changes. Wait a minute and try again.';
            case 'unavailable':
            case 'upstream_error':
            case 'upstream_timeout':
                return UABypassApi::UNAVAILABLE_MESSAGE;
        }

        switch ($status) {
            case 401:
                return 'Your session has expired. Run `terminus auth:login` and try again.';
            case 403:
                return sprintf('You do not have access to %s.', $siteEnv);
            case 404:
                return 'User-Agent bypass is not available yet. The service is still rolling out; try again later.';
            default:
                return sprintf(
                    'Could not %s User-Agent bypass entries (HTTP %d).',
                    $action === 'list' ? 'list' : 'change',
                    $status
                );
        }
    }

    /**
     * Whether an error response is about the one hostname requested rather
     * than the session, the site or the service.
     *
     * @param mixed $data Decoded JSON body.
     */
    public static function isHostnameError($data): bool
    {
        $code = self::errorField($data, 'code');
        return $code === 'invalid_request' || strpos($code, 'hostname_') === 0;
    }

    /**
     * The entries from a success body.
     *
     * @param mixed $data Decoded JSON body.
     *
     * @return string[]
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public static function entries($data): array
    {
        if (!is_object($data) || !isset($data->entries) || !is_array($data->entries)) {
            throw new TerminusException(self::MALFORMED_MESSAGE);
        }
        foreach ($data->entries as $entry) {
            if (!is_string($entry)) {
                throw new TerminusException(self::MALFORMED_MESSAGE);
            }
        }
        return $data->entries;
    }

    /**
     * Whether the server classified the changed entry as browser-shaped.
     * Present on add and remove responses only.
     *
     * @param mixed $data Decoded JSON body.
     */
    public static function browserLike($data): bool
    {
        return is_object($data) && isset($data->browser_like) && $data->browser_like === true;
    }

    /**
     * Table rows for one hostname's entries.
     *
     * @param string[] $entries
     *
     * @return array<int, array<string, string>>
     */
    public static function rows(string $hostname, array $entries): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = ['hostname' => $hostname, 'entry' => $entry];
        }
        return $rows;
    }

    /**
     * One field of the error envelope as a single printable line, or '' when
     * the body is not the envelope.
     *
     * @param mixed $data Decoded JSON body.
     */
    private static function errorField($data, string $field): string
    {
        if (!is_object($data) || !isset($data->error) || !is_object($data->error)) {
            return '';
        }
        $value = $data->error->{$field} ?? '';
        if (!is_string($value)) {
            return '';
        }
        $value = (string) preg_replace('/[^\x20-\x7e]/', '', $value);
        return substr($value, 0, self::SERVER_MESSAGE_MAX_LENGTH);
    }
}

<?php

namespace Pantheon\TerminusGCDN;

use Pantheon\Terminus\Exceptions\TerminusException;

/**
 * Class BotBypassResponse.
 *
 * Pure mapping from the bot-bypass token endpoint's response to what the
 * command shows. No I/O, so it is unit-testable without a network.
 *
 * @package Pantheon\TerminusGCDN
 */
final class BotBypassResponse
{
    public const MALFORMED_MESSAGE = 'Unexpected response from the bot-bypass token service.';

    /**
     * Maps a non-200 status to a message the customer can act on, or null
     * for success. Never includes response body text.
     */
    public static function errorMessage(int $status, string $site): ?string
    {
        switch ($status) {
            case 200:
                return null;
            case 401:
                return 'Your session has expired. Run `terminus auth:login` and try again.';
            case 403:
                return sprintf('You do not have access to %s.', $site);
            case 404:
                return 'Bot-bypass tokens are not available yet. The service is still rolling out; try again later.';
            default:
                return sprintf('Could not fetch bot-bypass tokens (HTTP %d).', $status);
        }
    }

    /**
     * Converts the decoded response into table rows: the current token
     * first, then the successor when present.
     *
     * @param mixed $data Decoded JSON body.
     *
     * @return array<int, array<string, string>>
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public static function rows($data): array
    {
        if (!is_object($data) || empty($data->token) || empty($data->header_name)) {
            throw new TerminusException(self::MALFORMED_MESSAGE);
        }

        $header = (string) $data->header_name;
        $rows = [self::row('current', $data, $header)];

        if (isset($data->next) && is_object($data->next) && !empty($data->next->token)) {
            $rows[] = self::row('next', $data->next, $header);
        }

        return $rows;
    }

    /**
     * Human guidance lines to print alongside the rows.
     *
     * @param array<int, array<string, string>> $rows Output of rows().
     *
     * @return string[]
     */
    public static function guidance(array $rows): array
    {
        $current = $rows[0];
        $next = $rows[1] ?? null;

        $lines = [
            sprintf(
                'Send the current token in the "%s" request header. One token covers every environment on the site.',
                $current['header_name']
            ),
        ];

        if ($next !== null) {
            $lines[] = sprintf(
                'Switch to the next token on or after %s. Both tokens are accepted until %s.',
                self::date($next['valid_from']),
                self::date($current['expires_at'])
            );
        } else {
            $lines[] = 'A successor token is not available yet. Re-run this command later to get it.';
        }

        $lines[] = 'Treat these tokens as secrets: do not commit them or paste them into shared logs.';

        return $lines;
    }

    private static function row(string $role, object $token, string $header): array
    {
        return [
            'role' => $role,
            'token' => (string) $token->token,
            'valid_from' => (string) ($token->valid_from ?? ''),
            'expires_at' => (string) ($token->expires_at ?? ''),
            'header_name' => $header,
        ];
    }

    private static function date(string $iso): string
    {
        $ts = strtotime($iso);
        return $ts === false ? $iso : gmdate('Y-m-d', $ts);
    }
}

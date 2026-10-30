<?php

namespace Pantheon\TerminusGCDN\Tests;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusGCDN\UABypassApi;
use Pantheon\TerminusGCDN\UABypassResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UABypassResponseTest extends TestCase
{
    private const SITE_ENV = 'my-site.live';
    private const HOST = 'www.example.com';

    private function decode(array $body): object
    {
        return json_decode(json_encode($body), false);
    }

    private function envelope(string $code, string $message = 'server message'): object
    {
        return $this->decode(['error' => ['code' => $code, 'message' => $message]]);
    }

    public static function validEntries(): array
    {
        return [
            'tool with url' => ['ExampleMonitor/2.1 (+https://example.com/monitor)'],
            'single char' => ['a'],
            'max length' => [str_repeat('a', 256)],
            'inner spaces and symbols' => ["curl/8.9.1 x!#\$%&'()*+,-./:;<=>?@[]^_`{|}~"],
        ];
    }

    #[DataProvider('validEntries')]
    public function testValidEntries(string $entry): void
    {
        $this->assertNull(UABypassResponse::entryProblem($entry));
    }

    public static function invalidEntries(): array
    {
        return [
            'empty' => ['', 'must not be empty'],
            'too long' => [str_repeat('a', 257), 'at most 256'],
            'tab' => ["Bot\t1.0", 'printable ASCII'],
            'newline' => ["Bot/1.0\n", 'printable ASCII'],
            'non-ascii' => ['Bot/1.0 é', 'printable ASCII'],
            'double quote' => ['Bot "1.0"', 'double quote'],
            'backslash' => ['Bot\\1.0', 'backslash'],
            'leading space' => [' Bot/1.0', 'start or end with a space'],
            'trailing space' => ['Bot/1.0 ', 'start or end with a space'],
        ];
    }

    #[DataProvider('invalidEntries')]
    public function testInvalidEntriesNameTheRuleNotTheValue(string $entry, string $rule): void
    {
        $problem = UABypassResponse::entryProblem($entry);

        $this->assertNotNull($problem);
        $this->assertStringContainsString($rule, $problem);
        if (trim($entry) !== '') {
            $this->assertStringNotContainsString(trim($entry), $problem);
        }
    }

    public function testHostnameIsLowercased(): void
    {
        $this->assertSame('www.example.com', UABypassResponse::hostname('WWW.Example.COM'));
    }

    public static function invalidHostnames(): array
    {
        return [
            'empty' => [''],
            'wildcard' => ['*.example.com'],
            'trailing dot' => ['example.com.'],
            'leading hyphen' => ['-bad.example.com'],
            'trailing hyphen' => ['bad-.example.com'],
            'underscore' => ['bad_host.example.com'],
            'long label' => [str_repeat('a', 64) . '.com'],
            'too long' => [str_repeat('a.', 127) . 'com'],
            'with path' => ['example.com/path'],
            'trailing newline' => ["example.com\n"],
        ];
    }

    #[DataProvider('invalidHostnames')]
    public function testInvalidHostnamesAreRejected(string $hostname): void
    {
        $this->expectException(TerminusException::class);
        UABypassResponse::hostname($hostname);
    }

    public function testSuccessHasNoError(): void
    {
        $this->assertNull(UABypassResponse::errorMessage(200, $this->decode(['entries' => []]), self::SITE_ENV, self::HOST, 'list'));
    }

    public static function codedErrors(): array
    {
        return [
            'unauthenticated' => [401, 'unauthenticated', 'terminus auth:login'],
            'permission denied' => [403, 'permission_denied', 'do not have access to my-site.live'],
            'browser shaped' => [400, 'browser_ua_rejected', '--allow-browser-ua'],
            'not found' => [404, 'hostname_not_found', 'terminus domain:list my-site.live'],
            'not verified' => [409, 'hostname_not_verified', 'terminus gcdn:verify my-site.live www.example.com'],
            'not provisioned' => [409, 'hostname_not_provisioned', 'not provisioned on GCDN'],
            'not cloudflare' => [409, 'hostname_not_cloudflare', 'not served by GCDN'],
            'limit' => [409, 'entry_limit_reached', 'gcdn:ua-bypass:remove'],
            'rate limited' => [429, 'rate_limited', 'Wait a minute'],
            'unavailable' => [503, 'unavailable', 'gcdn:ua-bypass:list'],
            'upstream error' => [502, 'upstream_error', 'gcdn:ua-bypass:list'],
            'upstream timeout' => [504, 'upstream_timeout', 'gcdn:ua-bypass:list'],
        ];
    }

    #[DataProvider('codedErrors')]
    public function testCodedErrorsAreActionable(int $status, string $code, string $expected): void
    {
        $message = UABypassResponse::errorMessage($status, $this->envelope($code), self::SITE_ENV, self::HOST, 'add');

        $this->assertNotNull($message);
        $this->assertStringContainsString($expected, $message);
        $this->assertStringNotContainsString('server message', $message);
    }

    public function testBrowserRejectionExplainsTheRisk(): void
    {
        $message = UABypassResponse::errorMessage(400, $this->envelope('browser_ua_rejected'), self::SITE_ENV, self::HOST, 'add');

        $this->assertStringContainsString('bots that imitate that browser', $message);
        $this->assertStringContainsString(self::HOST, $message);
    }

    public function testInvalidRequestRelaysTheRuleFromTheServer(): void
    {
        $message = UABypassResponse::errorMessage(
            400,
            $this->envelope('invalid_request', 'entry must not contain a double quote or a backslash'),
            self::SITE_ENV,
            self::HOST,
            'add'
        );

        $this->assertSame('The request was rejected: entry must not contain a double quote or a backslash.', $message);
    }

    public function testRelayedMessageIsStrippedAndBounded(): void
    {
        $message = UABypassResponse::errorMessage(
            400,
            $this->envelope('invalid_request', "bad\x1b[31m\n" . str_repeat('x', 1000)),
            self::SITE_ENV,
            self::HOST,
            'add'
        );

        $this->assertStringNotContainsString("\x1b", $message);
        $this->assertStringNotContainsString("\n", $message);
        $this->assertLessThan(600, strlen($message));
    }

    public function testNotFoundWithoutEnvelopeMeansRouteNotDeployed(): void
    {
        $message = UABypassResponse::errorMessage(404, '<html>not found</html>', self::SITE_ENV, self::HOST, 'list');

        $this->assertStringContainsString('not available yet', $message);
    }

    public function testUnknownStatusFallsBack(): void
    {
        $this->assertSame(
            'Could not change User-Agent bypass entries (HTTP 418).',
            UABypassResponse::errorMessage(418, null, self::SITE_ENV, self::HOST, 'remove')
        );
        $this->assertSame(
            'Could not list User-Agent bypass entries (HTTP 418).',
            UABypassResponse::errorMessage(418, null, self::SITE_ENV, self::HOST, 'list')
        );
    }

    public function testUnavailableNamesTheListCommandToCheck(): void
    {
        $this->assertStringContainsString('gcdn:ua-bypass:list', UABypassApi::UNAVAILABLE_MESSAGE);
    }

    public function testHostnameErrorsAreDistinguishedFromGlobalOnes(): void
    {
        $this->assertTrue(UABypassResponse::isHostnameError($this->envelope('hostname_not_verified')));
        $this->assertTrue(UABypassResponse::isHostnameError($this->envelope('hostname_not_found')));
        $this->assertTrue(UABypassResponse::isHostnameError($this->envelope('invalid_request')));
        $this->assertFalse(UABypassResponse::isHostnameError($this->envelope('permission_denied')));
        $this->assertFalse(UABypassResponse::isHostnameError($this->envelope('unavailable')));
        $this->assertFalse(UABypassResponse::isHostnameError('<html>not found</html>'));
        $this->assertFalse(UABypassResponse::isHostnameError(null));
    }

    public function testEntriesAndRows(): void
    {
        $data = $this->decode(['entries' => ['BotA/1.0', 'BotB/2.0'], 'browser_like' => false]);

        $entries = UABypassResponse::entries($data);

        $this->assertSame(['BotA/1.0', 'BotB/2.0'], $entries);
        $this->assertSame([
            ['hostname' => self::HOST, 'entry' => 'BotA/1.0'],
            ['hostname' => self::HOST, 'entry' => 'BotB/2.0'],
        ], UABypassResponse::rows(self::HOST, $entries));
    }

    public function testEmptyEntries(): void
    {
        $this->assertSame([], UABypassResponse::entries($this->decode(['entries' => []])));
        $this->assertSame([], UABypassResponse::rows(self::HOST, []));
    }

    public static function malformedBodies(): array
    {
        return [
            'null' => [null],
            'string' => ['oops'],
            'missing entries' => [(object) ['browser_like' => true]],
            'entries not array' => [(object) ['entries' => 'BotA/1.0']],
            'non-string entry' => [(object) ['entries' => ['BotA/1.0', 7]]],
        ];
    }

    #[DataProvider('malformedBodies')]
    public function testMalformedBodiesAreRejected($data): void
    {
        $this->expectException(TerminusException::class);
        $this->expectExceptionMessage(UABypassResponse::MALFORMED_MESSAGE);
        UABypassResponse::entries($data);
    }

    public function testBrowserLike(): void
    {
        $this->assertTrue(UABypassResponse::browserLike($this->decode(['entries' => [], 'browser_like' => true])));
        $this->assertFalse(UABypassResponse::browserLike($this->decode(['entries' => [], 'browser_like' => false])));
        $this->assertFalse(UABypassResponse::browserLike($this->decode(['entries' => []])));
        $this->assertFalse(UABypassResponse::browserLike($this->decode(['entries' => [], 'browser_like' => 'true'])));
    }
}

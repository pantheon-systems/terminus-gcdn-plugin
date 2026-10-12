<?php

namespace Pantheon\TerminusGCDN\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use League\Container\Container;
use Pantheon\Terminus\Config\TerminusConfig;
use Pantheon\Terminus\Exceptions\TerminusUnsupportedSiteException;
use Pantheon\Terminus\Request\Request;
use Pantheon\Terminus\Session\Session;
use Pantheon\TerminusGCDN\BotBypassApi;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Drives the real Terminus Request (URL building, headers, JSON decoding,
 * 409 handling) with a Guzzle MockHandler passed through `client_options`.
 * That handler replaces core's retry middleware, so retries aren't covered
 * here. Guzzle drops the default :443 from the URI it sends.
 */
final class RealRequestPipelineTest extends TestCase
{
    private array $history = [];

    private function request(array $responses): Request
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $config = new TerminusConfig();
        $config->set('protocol', 'https');
        $config->set('host', 'terminus.pantheon.io');
        $config->set('port', 443);
        // Terminus' constants.yml sets http_errors=false; keep it.
        $config->set('client_options', ['handler' => $stack, 'http_errors' => false]);

        $container = new Container();
        $container->add('input', new ArrayInput([]));

        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(fn($key) => $key === 'session' ? 'SESSION-TOKEN' : null);

        $request = new Request();
        $request->setConfig($config);
        $request->setContainer($container);
        $request->setLogger(new NullLogger());
        $request->setSession($session);
        $request->setInput(new ArrayInput([]));
        $request->setOutput(new NullOutput());
        return $request;
    }

    public function testRelativePathGetsApiPrefixAndBearer(): void
    {
        $result = $this->request([new Response(200, [], '[{"id":"www.example.com"}]')])
            ->request('sites/abc/environments/live/domains?hydrate[]=as_list', ['method' => 'get']);
        $sent = $this->history[0]['request'];
        $this->assertSame('https://terminus.pantheon.io/api/sites/abc/environments/live/domains?hydrate%5B%5D=as_list', (string) $sent->getUri());
        $this->assertSame('Bearer SESSION-TOKEN', $sent->getHeaderLine('Authorization'));
        $this->assertSame('www.example.com', $result->getData()[0]->id);
    }

    public function testTitanJsonStringBodyDecodesToAString(): void
    {
        // Titan's hostname PATCH answers with a JSON string, not an object.
        $result = $this->request([new Response(200, [], '"Hostname updated successfully."')])
            ->request('sites/abc/environments/live/hostnames/x', ['method' => 'PATCH', 'form_params' => ['verify_method' => 'http']]);
        $this->assertFalse($result->isError());
        $this->assertSame('Hostname updated successfully.', $result->getData());
        $this->assertSame('{"verify_method":"http"}', (string) $this->history[0]['request']->getBody());
    }

    public function testEmptyFormParamsSendsAnEmptyJsonObject(): void
    {
        // Force Recheck relies on titan receiving `{}` (a zero-length body is a 400).
        $this->request([new Response(200, [], '"Hostname updated successfully."')])
            ->request('sites/abc/environments/live/hostnames/x', ['method' => 'PATCH', 'form_params' => (object) []]);
        $this->assertSame('{}', (string) $this->history[0]['request']->getBody());
    }

    public function testNonJsonBodyIsReturnedAsIs(): void
    {
        $result = $this->request([new Response(200, [], 'Hostname updated successfully.')])
            ->request('sites/abc/environments/live/hostnames/x', ['method' => 'PATCH', 'form_params' => ['verify_method' => 'http']]);
        $this->assertSame('Hostname updated successfully.', $result->getData());
    }

    public function testClientErrorIsReturnedNotThrown(): void
    {
        $result = $this->request([new Response(400, [], "\"Bad params\\n\"")])
            ->request('sites/abc/workflows', ['method' => 'POST', 'form_params' => ['type' => 'cdn_migration']]);
        $this->assertTrue($result->isError());
        $this->assertSame(400, $result->getStatusCode());
        $this->assertSame("Bad params\n", $result->getData());
    }

    public function testConflictIsNeverASuccess(): void
    {
        // Terminus 4 throws on a 409 with a message; 3.2 returns it as an error.
        try {
            $result = $this->request([new Response(409, [], '{"message":"A workflow is already running"}')])
                ->request('sites/abc/workflows', ['method' => 'POST', 'form_params' => ['type' => 'cdn_migration']]);
            $this->assertTrue($result->isError());
            $this->assertSame(409, $result->getStatusCode());
        } catch (TerminusUnsupportedSiteException $e) {
            $this->assertSame('A workflow is already running', $e->getMessage());
        }
    }

    public function testBotBypassAbsoluteUrlSendsRawSession(): void
    {
        (new BotBypassApi($this->request([new Response(200, [], '{"token":"t","header_name":"h"}')])))->fetchTokens('abc');
        $sent = $this->history[0]['request'];
        $this->assertSame('https://terminus.pantheon.io/bot-bypass/v1/sites/abc/token', (string) $sent->getUri());
        $this->assertSame('SESSION-TOKEN', $sent->getHeaderLine('Authorization'));
    }
}

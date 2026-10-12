<?php

namespace Pantheon\TerminusGCDN\Tests\Support;

use Pantheon\Terminus\Collections\Environments;
use Pantheon\Terminus\Collections\Sites;
use Pantheon\Terminus\Config\TerminusConfig;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Request\Request;
use Pantheon\Terminus\Request\RequestOperationResult;
use Pantheon\Terminus\Session\Session;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Offline harness for plugin commands.
 *
 * Wires a command to mocked Sites -> Site -> Environments -> Environment and
 * a scripted Request. Every request is recorded, and any request that isn't
 * scripted fails the test, so nothing can reach the network.
 *
 * The only site is `my-site` and the only environment is `live`.
 */
abstract class CommandHarness extends TestCase
{
    public const SITE_ID = '11111111-2222-3333-4444-555555555555';
    public const DOMAINS_PATH = '#^sites/[^/]+/environments/live/domains\?#';

    /** @var array<int, array{0:string,1:string,2:array}> method, path, options */
    protected array $calls = [];

    /** @var array<int, array{0:string,1:string,2:callable|RequestOperationResult}> */
    protected array $script = [];

    protected BufferedOutput $out;
    protected ArrayLogger $logger;

    protected function apiResult(int $status, $data): RequestOperationResult
    {
        return new RequestOperationResult([
            'data' => $data,
            'headers' => [],
            'status_code' => $status,
            // RequestOperationResult fails to construct without it.
            'status_code_reason' => '',
        ]);
    }

    /**
     * Loads tests/fixtures/<name> as decoded JSON (objects, like Terminus).
     */
    protected function fixture(string $name)
    {
        return json_decode(file_get_contents(__DIR__ . '/../fixtures/' . $name), false);
    }

    /**
     * Loads a domain fixture, optionally without the EDRT-9618 challenge
     * keys (as the API returns before policy-docs #318 deploys).
     */
    protected function domain(string $name, bool $withEdrt9618Keys = true): object
    {
        $domain = $this->fixture('domains/' . $name . '.json');
        if (!$withEdrt9618Keys && isset($domain->challenges) && is_object($domain->challenges)) {
            foreach (DomainFixtures::EDRT_9618_KEYS as $key) {
                unset($domain->challenges->{$key});
            }
        }
        return $domain;
    }

    /**
     * Scripts the domains list GET to return the given domain objects.
     */
    protected function domainsList(object ...$domains): void
    {
        $this->on('GET', self::DOMAINS_PATH, $this->apiResult(200, array_values($domains)));
    }

    /**
     * Scripts a response: method + path regex => result, or a callable that
     * receives (path, options) and returns a result or throws.
     */
    protected function on(string $method, string $pathRegex, $response): void
    {
        $this->script[] = [strtoupper($method), $pathRegex, $response];
    }

    protected function wire($command, array $configValues = []): void
    {
        $env = $this->createMock(Environment::class);
        $env->id = 'live';
        $env->method('getName')->willReturn('live');

        $envs = $this->createMock(Environments::class);
        $envs->method('get')->willReturnCallback(function ($id) use ($env) {
            if ($id !== 'live') {
                throw new TerminusNotFoundException("Could not find an environment identified by {$id}.");
            }
            return $env;
        });

        $site = $this->createMock(Site::class);
        $site->id = self::SITE_ID;
        $site->method('getName')->willReturn('my-site');
        $site->method('getEnvironments')->willReturn($envs);

        $sites = $this->createMock(Sites::class);
        $sites->method('get')->willReturnCallback(function ($id) use ($site) {
            $this->calls[] = ['SITES.GET', $id, []];
            if ($id !== 'my-site') {
                throw new TerminusException("Could not locate a site your user may access identified by {$id}");
            }
            return $site;
        });

        $config = new TerminusConfig();
        foreach ($configValues + ['protocol' => 'https', 'host' => 'terminus.pantheon.io', 'port' => 443] as $key => $value) {
            $config->set($key, $value);
        }

        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(fn($key) => $key === 'session' ? 'SESSION-TOKEN' : null);

        $request = $this->createMock(Request::class);
        $request->method('getConfig')->willReturn($config);
        $request->method('session')->willReturn($session);
        $request->method('request')->willReturnCallback(function ($path, array $options = []) {
            $method = strtoupper($options['method'] ?? 'GET');
            $this->calls[] = [$method, $path, $options];
            foreach ($this->script as [$scriptedMethod, $pathRegex, $response]) {
                if ($scriptedMethod === $method && preg_match($pathRegex, $path)) {
                    return is_callable($response) ? $response($path, $options) : $response;
                }
            }
            $this->fail("Unscripted request: {$method} {$path}");
        });

        $this->out = new BufferedOutput();
        $this->logger = new ArrayLogger();

        $command->setSites($sites);
        $command->setRequest($request);
        $command->setLogger($this->logger);
        $command->setConfig($config);
        $command->setInput(new ArrayInput([]));
        $command->setOutput($this->out);
    }

    /**
     * Command output with ANSI codes stripped. Each call returns only the
     * output written since the previous call.
     */
    protected function plain(): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $this->out->fetch());
    }

    /**
     * Requests that change state (POST, PATCH, PUT, DELETE), in order.
     */
    protected function writes(): array
    {
        return array_values(array_filter(
            $this->calls,
            fn($call) => in_array($call[0], ['POST', 'PATCH', 'PUT', 'DELETE'], true)
        ));
    }

    /**
     * Every recorded call as "METHOD path", for exact-sequence assertions.
     */
    protected function callLog(): array
    {
        return array_map(fn($call) => $call[0] . ' ' . $call[1], $this->calls);
    }
}

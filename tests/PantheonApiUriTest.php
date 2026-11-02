<?php

namespace Pantheon\TerminusGCDN\Tests;

use Consolidation\Config\Config;
use Pantheon\TerminusGCDN\PantheonApiUri;
use PHPUnit\Framework\TestCase;

final class PantheonApiUriTest extends TestCase
{
    private function config(array $values): Config
    {
        return new Config(array_merge(['protocol' => 'https', 'port' => 443], $values));
    }

    public function testProductionFallsBackToTerminusHost(): void
    {
        $this->assertSame(
            'https://terminus.pantheon.io:443/ua-bypass/v1',
            PantheonApiUri::base($this->config(['host' => 'terminus.pantheon.io']), 'ua-bypass/v1')
        );
    }

    public function testHermesSandboxHostIsRewrittenToPantheonapi(): void
    {
        $this->assertSame(
            'https://pantheonapi.sandbox-edrt-cloudflare-integration.sbx02.pantheon.io:443/bot-bypass/v1',
            PantheonApiUri::base(
                $this->config(['host' => 'hermes.sandbox-edrt-cloudflare-integration.sbx02.pantheon.io']),
                'bot-bypass/v1'
            )
        );
    }

    public function testOtherSandboxHostIsUsedAsIs(): void
    {
        $this->assertSame(
            'https://terminus.sandbox-x.sbx02.pantheon.io:443/ua-bypass/v1',
            PantheonApiUri::base($this->config(['host' => 'terminus.sandbox-x.sbx02.pantheon.io']), 'ua-bypass/v1')
        );
    }

    public function testPapiOverridesWin(): void
    {
        $config = $this->config([
            'host' => 'hermes.sandbox-x.sbx02.pantheon.io',
            'papi_host' => 'localhost',
            'papi_protocol' => 'http',
            'papi_port' => 8080,
        ]);

        $this->assertSame('http://localhost:8080/ua-bypass/v1', PantheonApiUri::base($config, 'ua-bypass/v1'));
    }
}

<?php

namespace Pantheon\TerminusGCDN\Tests\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusGCDN\Commands\UpgradeCommand;
use Pantheon\TerminusGCDN\Tests\Support\CommandHarness;

/**
 * gcdn:upgrade.
 *
 * Titan's `POST sites/{id}/workflows` answers 202 on success. Its errors are
 * bare JSON strings ("Bad params\n", "Forbidden\n", "Conflict\n"), and a
 * re-run on a migrated site succeeds, because policy-docs' migration is
 * idempotent (titan-mt `yggdrasil/web/request.py:56-176`, policy-docs
 * `site_env.go:381-430`).
 */
final class UpgradeCommandTest extends CommandHarness
{
    private const WORKFLOWS = '#^sites/[^/]+/workflows$#';

    private function upgrade(string $site, $migrateResult, $convergeResult = null)
    {
        $cmd = new UpgradeCommand();
        $this->wire($cmd);
        $this->on('POST', self::WORKFLOWS, function ($path, $options) use ($migrateResult, $convergeResult) {
            return $options['form_params']['type'] === 'cdn_migration'
                ? $migrateResult
                : ($convergeResult ?? $this->apiResult(202, (object) ['id' => 'wf-converge']));
        });
        return $cmd->upgrade($site);
    }

    public function testHappyPathPostsMigrationThenConverge(): void
    {
        $this->assertNull($this->upgrade('my-site', $this->apiResult(202, (object) ['id' => 'wf-migrate'])));
        $writes = $this->writes();
        $this->assertCount(2, $writes);
        $this->assertSame('sites/' . self::SITE_ID . '/workflows', $writes[0][1]);
        $this->assertSame('cdn_migration', $writes[0][2]['form_params']['type']);
        $this->assertSame(['dev', 'test', 'live'], $writes[0][2]['form_params']['params']->environments);
        $this->assertSame('converge_site', $writes[1][2]['form_params']['type']);
        $this->assertStringContainsString('GCDN upgrade complete for my-site.', $this->logger->text());
    }

    public function testConvergeFailureIsAWarningAndStillSaysComplete(): void
    {
        $this->upgrade('my-site', $this->apiResult(202, (object) []), $this->apiResult(501, ''));
        $log = $this->logger->text();
        $this->assertStringContainsString('warning: Failed to trigger site converge', $log);
        $this->assertStringContainsString('GCDN upgrade complete', $log);
    }

    public function testUnknownSiteFailsBeforeAnyRequest(): void
    {
        $cmd = new UpgradeCommand();
        $this->wire($cmd);
        $this->expectException(TerminusException::class);
        try {
            $cmd->upgrade('not-my-site');
        } finally {
            $this->assertSame([], $this->writes());
        }
    }

    /** U1: an error mentioning "cloudflare" fails the command; no converge, no "complete". */
    public function testU1CloudflareErrorFails(): void
    {
        try {
            $this->upgrade('my-site', $this->apiResult(422, (object) ['message' => 'Failed to create Cloudflare custom hostname']));
            $this->fail('expected exception');
        } catch (TerminusException $e) {
            $this->assertSame('Failed to migrate my-site to new-gcdn: HTTP 422: Failed to create Cloudflare custom hostname', $e->getMessage());
        }
        $this->assertCount(1, $this->writes(), 'no converge after a failed migration');
        $this->assertStringNotContainsString('already migrated', $this->logger->text());
        $this->assertStringNotContainsString('upgrade complete', $this->logger->text());
    }

    /** U1: "already" in an unrelated error fails too. */
    public function testU1AlreadyInProgressFails(): void
    {
        $this->expectException(TerminusException::class);
        $this->expectExceptionMessage('HTTP 400: A cdn_migration workflow is already in progress');
        $this->upgrade('my-site', $this->apiResult(400, (object) ['message' => 'A cdn_migration workflow is already in progress']));
    }

    /** U2: titan's string error bodies are kept. */
    public function testU2StringErrorBodyIsKept(): void
    {
        try {
            $this->upgrade('my-site', $this->apiResult(400, "Bad params\n"));
            $this->fail('expected exception');
        } catch (TerminusException $e) {
            $this->assertSame('Failed to migrate my-site to new-gcdn: HTTP 400: Bad params', $e->getMessage());
        }
        $this->assertCount(1, $this->writes());
    }

    public function testHtmlAndEmptyBodiesGiveTheStatusOnly(): void
    {
        foreach (['<html><body>502 Bad Gateway</body></html>', '', null] as $body) {
            try {
                $this->upgrade('my-site', $this->apiResult(501, $body));
                $this->fail('expected exception');
            } catch (TerminusException $e) {
                $this->assertSame('Failed to migrate my-site to new-gcdn: HTTP 501', $e->getMessage());
            }
        }
    }

    /** U3: `site.env` still migrates dev, test and live, with no confirmation. Separate ticket. */
    public function testU3SiteDotEnvMigratesEveryEnvironment(): void
    {
        $this->upgrade('my-site.dev', $this->apiResult(202, (object) []));
        $this->assertSame(['dev', 'test', 'live'], $this->writes()[0][2]['form_params']['params']->environments);
        $this->markTestIncomplete('U3 (separate ticket): reject or confirm a site.env argument, and add --yes.');
    }

    /** U4: converge_site is POSTed without waiting for the migration workflow. Separate ticket. */
    public function testU4ConvergeDoesNotWaitForMigration(): void
    {
        $this->upgrade('my-site', $this->apiResult(202, (object) ['id' => 'wf-migrate']));
        $this->assertSame(['cdn_migration', 'converge_site'], array_map(fn($w) => $w[2]['form_params']['type'], $this->writes()));
        $this->markTestIncomplete('U4 (separate ticket): wait for the cdn_migration workflow before converging.');
    }
}

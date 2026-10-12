<?php

namespace Pantheon\TerminusGCDN\Tests\Support;

use Pantheon\TerminusGCDN\Commands\VerifyCommand;

/**
 * gcdn:verify with DNS lookups and the clock replaced, so tests never touch
 * the network and control the recheck cooldown.
 */
final class TestVerifyCommand extends VerifyCommand
{
    /** @var array<string, string> host => CNAME target */
    public array $cnames = [];

    /** @var array<string, string[]> host => addresses */
    public array $addresses = [];

    /** @var string[] every host looked up */
    public array $lookups = [];

    /** The fixtures' observed_at (2026-10-11T18:04:05Z). */
    public const OBSERVED_AT = 1791741845;

    /** An hour after the fixtures' observed_at. */
    public int $clock = self::OBSERVED_AT + 3600;

    protected function lookupCname(string $host): ?string
    {
        $this->lookups[] = $host;
        return $this->cnames[$host] ?? null;
    }

    protected function lookupAddresses(string $host): array
    {
        $this->lookups[] = $host;
        return $this->addresses[$host] ?? [];
    }

    protected function now(): int
    {
        return $this->clock;
    }
}

<?php

namespace Pantheon\TerminusGCDN\Tests\Support;

use Pantheon\TerminusGCDN\Commands\VerifyCommand;

/**
 * gcdn:verify with DNS lookups and the clock replaced, so tests never touch
 * the network and control the recheck cooldown.
 */
final class TestVerifyCommand extends VerifyCommand
{
    /** @var array<string, array<string, string[]>> type => host => values; absent = lookup failed */
    public array $records = [];

    /** @var string[] "TYPE host" for every record lookup */
    public array $recordLookups = [];

    /** The fixtures' observed_at (2026-10-11T18:04:05Z). */
    public const OBSERVED_AT = 1791741845;

    /** An hour after the fixtures' observed_at. */
    public int $clock = self::OBSERVED_AT + 3600;

    protected function lookupRecords(string $host, string $type): ?array
    {
        $this->recordLookups[] = "{$type} {$host}";
        return $this->records[$type][$host] ?? null;
    }

    protected function now(): int
    {
        return $this->clock;
    }
}

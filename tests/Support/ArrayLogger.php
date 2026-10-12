<?php

namespace Pantheon\TerminusGCDN\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Records log lines so tests can assert on notices and warnings.
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var array<int, array{0:string,1:string}> */
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }
        $this->lines[] = [(string) $level, strtr((string) $message, $replacements)];
    }

    public function text(): string
    {
        return implode("\n", array_map(fn($line) => $line[0] . ': ' . $line[1], $this->lines));
    }
}

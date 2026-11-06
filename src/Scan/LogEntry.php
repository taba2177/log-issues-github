<?php

declare(strict_types=1);

namespace Taba\LogIssues\Scan;

/**
 * One parsed entry of a Laravel log file.
 */
final class LogEntry
{
    /**
     * @param  list<array<string, mixed>>  $trace  PHP-trace shaped frames.
     * @param  list<array{class: string, message: string}>  $previous
     */
    public function __construct(
        public readonly string $datetime,
        public readonly string $environment,
        public readonly string $level,
        public readonly string $message,
        public readonly ?string $exceptionClass = null,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        public readonly array $trace = [],
        public readonly array $previous = [],
    ) {}
}

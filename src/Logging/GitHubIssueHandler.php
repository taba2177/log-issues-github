<?php

declare(strict_types=1);

namespace Taba\LogIssues\Logging;

use Illuminate\Contracts\Container\Container;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Taba\LogIssues\Pipeline\Reporter;
use Taba\LogIssues\Support\Guard;
use Taba\LogIssues\Support\SafeLog;
use Throwable;

/**
 * Monolog handler behind the "github-issues" log channel.
 *
 * Golden rule: it never throws, never recurses and never blocks the request for long.
 * Dependencies are resolved lazily so that creating the channel itself cannot fail.
 */
final class GitHubIssueHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly Container $container,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    public function handle(LogRecord $record): bool
    {
        if (! $this->isHandling($record)) {
            return false;
        }

        // Skip processors and formatters: the package builds its own report.
        $this->write($record);

        return $this->bubble === false;
    }

    protected function write(LogRecord $record): void
    {
        if (Guard::active()) {
            return; // a log written while the package itself is working: drop it
        }

        try {
            Guard::run(fn (): ?string => $this->container->make(Reporter::class)->handleRecord($record));
        } catch (Throwable $e) {
            SafeLog::write($e);
        }
    }
}

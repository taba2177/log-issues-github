<?php

declare(strict_types=1);

namespace Taba\LogIssues\Pipeline;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Foundation\Application;
use Taba\LogIssues\Jobs\ReportToGitHub;
use Taba\LogIssues\Settings;
use Taba\LogIssues\Support\SafeLog;
use Throwable;

/**
 * Chooses how a report reaches GitHub: a queue when configured, otherwise after the HTTP
 * response, otherwise (CLI) synchronously with the client's short timeout.
 */
final class ReportDispatcher
{
    public const QUEUE = 'queue';

    public const AFTER_RESPONSE = 'after_response';

    public const SYNC = 'sync';

    public function __construct(
        private readonly Application $app,
        private readonly Bus $bus,
        private readonly Settings $settings,
    ) {}

    public function dispatch(ReportToGitHub $job): string
    {
        $queue = $this->settings->queue();

        if ($queue !== false) {
            $job->onConnection($queue['connection'])->onQueue($queue['name']);
            $this->bus->dispatch($job);

            return self::QUEUE;
        }

        if (! $this->app->runningInConsole()) {
            $this->app->terminating(fn () => $this->run($job));

            return self::AFTER_RESPONSE;
        }

        $this->run($job);

        return self::SYNC;
    }

    private function run(ReportToGitHub $job): void
    {
        try {
            $this->app->call([$job, 'handle']);
        } catch (Throwable $e) {
            SafeLog::write($e);
        }
    }
}

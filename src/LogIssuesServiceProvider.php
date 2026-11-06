<?php

declare(strict_types=1);

namespace Taba\LogIssues;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Taba\LogIssues\Console\ScanCommand;
use Taba\LogIssues\Console\StatusCommand;
use Taba\LogIssues\Console\TestCommand;
use Taba\LogIssues\GitHub\GitHubClient;
use Taba\LogIssues\GitHub\IssueFormatter;
use Taba\LogIssues\Logging\GitHubIssueHandler;
use Taba\LogIssues\Pipeline\GitHubSync;
use Taba\LogIssues\Pipeline\OccurrenceStore;
use Taba\LogIssues\Pipeline\ReportDispatcher;
use Taba\LogIssues\Pipeline\Reporter;
use Taba\LogIssues\Report\Fingerprinter;
use Taba\LogIssues\Report\Redactor;
use Taba\LogIssues\Report\ReportBuilder;
use Taba\LogIssues\Scan\LogFileParser;
use Taba\LogIssues\Support\Paths;
use Taba\LogIssues\Support\ReleaseResolver;
use Taba\LogIssues\Support\RuntimeContext;
use Taba\LogIssues\Support\SafeLog;
use Throwable;

final class LogIssuesServiceProvider extends ServiceProvider
{
    public const CHANNEL = 'github-issues';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/log-issues.php', 'log-issues');

        foreach ([
            Settings::class, Paths::class, ReleaseResolver::class, RuntimeContext::class,
            Fingerprinter::class, Redactor::class, ReportBuilder::class, OccurrenceStore::class,
            GitHubClient::class, IssueFormatter::class, GitHubSync::class, ReportDispatcher::class,
            Reporter::class, LogFileParser::class,
        ] as $service) {
            $this->app->singleton($service);
        }

        // Registered as early as possible so errors raised while other providers boot are covered.
        $this->registerChannel();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/log-issues.php' => $this->app->configPath('log-issues.php'),
            ], 'log-issues-config');

            $this->commands([TestCommand::class, ScanCommand::class, StatusCommand::class]);
        }

        try {
            /** @var Dispatcher $events */
            $events = $this->app->make('events');
            $events->listen(JobProcessing::class, function (JobProcessing $event): void {
                try {
                    $this->app->make(RuntimeContext::class)->setJob($event->job->resolveName());
                } catch (Throwable) {
                    // best effort
                }
            });
            $events->listen(JobProcessed::class, function (): void {
                $this->app->make(RuntimeContext::class)->setJob(null);
            });
        } catch (Throwable $e) {
            SafeLog::write($e);
        }
    }

    /** Adds the "github-issues" channel unless the app already defines one with that name. */
    private function registerChannel(): void
    {
        try {
            /** @var Repository $config */
            $config = $this->app->make('config');

            if ($config->has('logging.channels.'.self::CHANNEL)) {
                return;
            }

            $config->set('logging.channels.'.self::CHANNEL, [
                'driver' => 'monolog',
                'handler' => GitHubIssueHandler::class,
                'level' => $config->get('log-issues.level', 'error'),
            ]);
        } catch (Throwable $e) {
            SafeLog::write($e);
        }
    }
}
